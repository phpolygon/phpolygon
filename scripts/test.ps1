<#
.SYNOPSIS
  Runs the full test suite: in parallel with ParaTest, then the serial groups with PHPUnit.

.DESCRIPTION
  Two parts, one combined exit code:
    1. parallel: everything except the groups 'gpu' and 'timing' (ParaTest, -Processes workers)
    2. serial:   the groups 'gpu' and 'timing' (PHPUnit, one process)

  #[Group('gpu')]    tests that open a vio context or a window. There is one GPU, so they run
                     one after the other (the D3D12 exact-hash tests included).
  #[Group('timing')] tests that assert wall-clock limits or child-process liveness; they fail
                     under full CPU load.
  Tests that write files use a directory of their own per test (or per process) so they can run in
  parallel; a test that cannot do that belongs into one of the serial groups.

.PARAMETER Processes
  Number of ParaTest worker processes (default 6).

.PARAMETER Filter
  Optional PHPUnit --filter pattern, applied to both parts.

.PARAMETER NoGpu
  Skip the serial 'gpu' group (hosts without a usable GPU or software adapter).

.PARAMETER ExcludeGroup
  Further groups to leave out of both parts (default 'font-vrt', as in CI: FreeType snapshots belong to the Alpine job).

.EXAMPLE
  scripts/test.ps1
  scripts/test.ps1 -Processes 4 -Filter 'Geometry'
  scripts/test.ps1 -NoGpu
#>
param(
    [int]$Processes = 6,
    [string]$Filter = '',
    [switch]$NoGpu,
    [string]$ExcludeGroup = 'font-vrt'
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$filterArgs = @()
if ($Filter -ne '') {
    $filterArgs = @('--filter', $Filter)
}

# One option per group: comma lists are deprecated in PHPUnit 11 and the warnings fail the run.
$serialGroups = if ($NoGpu) { @('timing') } else { @('gpu', 'timing') }
$parallelExclude = @('gpu', 'timing')
if ($ExcludeGroup -ne '') {
    $parallelExclude += $ExcludeGroup
}
$parallelArgs = @()
foreach ($group in $parallelExclude) {
    $parallelArgs += @('--exclude-group', $group)
}
$serialArgs = @()
foreach ($group in $serialGroups) {
    $serialArgs += @('--group', $group)
}
if ($ExcludeGroup -ne '') {
    $serialArgs += @('--exclude-group', $ExcludeGroup)
}

Write-Host "== parallel run ($Processes processes, without groups $($parallelExclude -join ', '))"
& php -d memory_limit=-1 vendor/bin/paratest --processes=$Processes --passthru-php="-d memory_limit=-1" --no-progress @parallelArgs @filterArgs
$parallel = $LASTEXITCODE

Write-Host "== serial run (groups $($serialGroups -join ', '))"
& php -d memory_limit=-1 vendor/bin/phpunit @serialArgs @filterArgs
$serial = $LASTEXITCODE

if ($parallel -ne 0 -or $serial -ne 0) {
    Write-Host "FAILED: parallel exit $parallel, serial exit $serial"
    exit 1
}
Write-Host 'OK'
exit 0
