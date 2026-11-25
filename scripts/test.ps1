<#
.SYNOPSIS
  Runs the full test suite: ParaTest for the plain tests, ParaTest on the hardware GPU for the render tests,
  then the timing-sensitive tests alone.

.DESCRIPTION
  Three parts, one combined exit code:
    1. parallel: everything except the groups 'gpu' and 'timing' (-Processes workers)
    2. gpu:      group 'gpu' without 'timing', -GpuProcesses workers, each with its own headless context
                 on the hardware GPU (the GPU lock is taken once for the whole part)
    3. serial:   group 'timing' in one PHPUnit process

  #[Group('gpu')]    tests that open a vio context or a window.
  #[Group('timing')] tests that assert wall-clock limits or child-process liveness; they fail under
                     full CPU/GPU load and run alone at the end (a class in both groups runs here, not in 2).

  Render tests never run on a software adapter locally (WARP, llvmpipe): the script probes the adapter
  first. With only a software adapter available the gpu part is skipped with a message, or the run fails
  with -RequireGpu. CI (env CI / GITHUB_ACTIONS) keeps the software adapter; -SoftwareGpu asks for it
  explicitly and is only honoured there. PHPOLYGON_TEST_VIO_ADAPTER=Intel runs the render tests that go
  through VioTestContext on another hardware adapter.

.PARAMETER Processes
  ParaTest workers of the plain part (default 6).

.PARAMETER GpuProcesses
  ParaTest workers of the gpu part (default 6: bounded by the longest test class, see CLAUDE.md "Running the tests").

.PARAMETER Filter
  Optional PHPUnit --filter pattern, applied to every part.

.PARAMETER NoGpu
  Skip the gpu part.

.PARAMETER RequireGpu
  Fail instead of skipping when no hardware adapter is available.

.PARAMETER SoftwareGpu
  Run the gpu part on the software adapter (CI only).

.PARAMETER ExcludeGroup
  Further group left out of every part (default 'font-vrt', as in CI: FreeType snapshots belong to the Alpine job).

.EXAMPLE
  scripts/test.ps1
  scripts/test.ps1 -GpuProcesses 2 -Filter 'Geometry'
  scripts/test.ps1 -NoGpu
#>
param(
    [int]$Processes = 6,
    [int]$GpuProcesses = 6,
    [string]$Filter = '',
    [switch]$NoGpu,
    [switch]$RequireGpu,
    [switch]$SoftwareGpu,
    [string]$ExcludeGroup = 'font-vrt'
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$ci = $false
foreach ($name in 'CI', 'GITHUB_ACTIONS') {
    $value = [Environment]::GetEnvironmentVariable($name)
    if ($value -and $value -ne '0' -and $value -ne 'false') { $ci = $true }
}
if ($SoftwareGpu -and -not $ci) {
    Write-Host 'FAILED: -SoftwareGpu is only honoured in CI (render tests never run on a software adapter locally)'
    exit 1
}
if ($ci) { $SoftwareGpu = $true }

$filterArgs = @()
if ($Filter -ne '') {
    $filterArgs = @('--filter', $Filter)
}
$excludeArgs = @()
if ($ExcludeGroup -ne '') {
    $excludeArgs = @('--exclude-group', $ExcludeGroup)
}

function Invoke-ParaTest([int]$Workers, [string[]]$GroupArgs) {
    & php -d memory_limit=-1 vendor/bin/paratest --processes=$Workers --passthru-php="-d memory_limit=-1" --no-progress @GroupArgs @excludeArgs @filterArgs | Out-Host
    return [int]$LASTEXITCODE
}

# The GPU lock coordinates whole runs between people and agents: one holder at a time. A lock of a dead
# process is taken over.
function Enter-GpuLock {
    $path = $env:PHPOLYGON_GPU_LOCK
    if (-not $path) { $path = 'D:\PhpstormProjects\.gpu.lock' }
    if (-not (Test-Path (Split-Path -Parent $path))) { return $null }
    $deadline = (Get-Date).AddMinutes(60)
    while (Test-Path $path) {
        $holder = (Get-Content $path -ErrorAction SilentlyContinue | Select-Object -First 1)
        $holderPid = 0
        if ($holder -match '^\s*(\d+)') { $holderPid = [int]$Matches[1] }
        if ($holderPid -gt 0 -and -not (Get-Process -Id $holderPid -ErrorAction SilentlyContinue)) {
            Remove-Item $path -ErrorAction SilentlyContinue
            break
        }
        if ((Get-Date) -gt $deadline) { throw "GPU lock $path is held ($holder) for more than 60 minutes" }
        Write-Host "waiting for the GPU lock ($holder)"
        Start-Sleep -Seconds 15
    }
    "$PID phpolygon scripts/test.ps1 $(Get-Date -Format s)" | Set-Content $path
    return $path
}

# 1. plain tests in parallel
Write-Host "== parallel run ($Processes processes, without groups gpu, timing, $ExcludeGroup)"
$parallel = Invoke-ParaTest $Processes @('--exclude-group', 'gpu', '--exclude-group', 'timing')

# 2. render tests in parallel on the GPU
$gpu = 0
$gpuNote = ''
if (-not $NoGpu) {
    if (-not $SoftwareGpu) {
        $env:VIO_D3D_HEADLESS_HARDWARE = '1'
    }
    $class = (& php -r 'require "vendor/autoload.php"; echo PHPolygon\Tests\GpuPolicy::adapterClass();')
    Write-Host "== adapter class: $class"
    $env:PHPOLYGON_TEST_ADAPTER_CLASS = $class
    if (($class -eq 'software' -or $class -eq 'none') -and -not $SoftwareGpu) {
        if ($RequireGpu) {
            Write-Host "FAILED: -RequireGpu, but no hardware adapter is available (adapter class '$class')"
            $gpu = 1
        } else {
            $gpuNote = "gpu part SKIPPED: no hardware adapter (adapter class '$class'); render tests never run on software locally"
            Write-Host "== $gpuNote"
        }
    } else {
        $lock = Enter-GpuLock
        try {
            Write-Host "== gpu run ($GpuProcesses processes, group gpu without timing)"
            $gpu = Invoke-ParaTest $GpuProcesses @('--group', 'gpu', '--exclude-group', 'timing')
        } finally {
            if ($lock) { Remove-Item $lock -ErrorAction SilentlyContinue }
        }
    }
}

# 3. timing tests alone
Write-Host '== serial run (group timing)'
& php -d memory_limit=-1 vendor/bin/phpunit --group timing @excludeArgs @filterArgs
$serial = $LASTEXITCODE

if ($gpuNote -ne '') { Write-Host $gpuNote }
if ($parallel -ne 0 -or $gpu -ne 0 -or $serial -ne 0) {
    Write-Host "FAILED: parallel exit $parallel, gpu exit $gpu, serial exit $serial"
    exit 1
}
Write-Host 'OK'
exit 0
