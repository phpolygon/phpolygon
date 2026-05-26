import type { SceneJson } from '../runtime/types'

/**
 * Write a scene JSON back to disk. Uses the File System Access API where
 * available (Chromium: user picks the file, no server), otherwise falls back
 * to a download. Either way the next step is:
 *
 *   php bin/phpolygon scene:transpile <file>.scene.json --out src/Scene/X.php
 */
export async function saveSceneJson(scene: SceneJson): Promise<void> {
  const json = JSON.stringify(scene, null, 2)
  const filename = `${scene.name}.scene.json`

  const picker = (window as unknown as {
    showSaveFilePicker?: (opts: unknown) => Promise<FileSystemFileHandleLike>
  }).showSaveFilePicker

  if (typeof picker === 'function') {
    try {
      const handle = await picker({
        suggestedName: filename,
        types: [{ description: 'PHPolygon scene', accept: { 'application/json': ['.scene.json', '.json'] } }],
      })
      const writable = await handle.createWritable()
      await writable.write(json)
      await writable.close()
      return
    } catch (err) {
      if ((err as DOMException)?.name === 'AbortError') return // user cancelled
      // Any other failure: fall back to a download.
    }
  }

  downloadText(filename, json)
}

interface FileSystemWritableLike {
  write(data: string): Promise<void>
  close(): Promise<void>
}

interface FileSystemFileHandleLike {
  createWritable(): Promise<FileSystemWritableLike>
}

function downloadText(filename: string, text: string): void {
  const blob = new Blob([text], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}
