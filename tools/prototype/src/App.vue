<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Bundle } from './runtime/bundle'
import SceneView from './components/SceneView.vue'

const bundle = ref<Bundle | null>(null)
const selected = ref<string>('')
const error = ref<string>('')

const baseUrl = (import.meta.env.VITE_BUNDLE_URL as string | undefined) ?? '/bundle'
const sceneNames = computed(() => bundle.value?.sceneNames() ?? [])

onMounted(async () => {
  try {
    bundle.value = await Bundle.load(baseUrl)
    selected.value = sceneNames.value[0] ?? ''
  } catch (e) {
    error.value =
      `Could not load bundle from ${baseUrl}. Export it first:\n` +
      `php bin/phpolygon prototype:export --out tools/prototype/public/bundle\n(${e})`
  }
})

async function downloadScene(): Promise<void> {
  if (!bundle.value || !selected.value) return
  const scene = await bundle.value.loadScene(selected.value)
  const blob = new Blob([JSON.stringify(scene, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${selected.value}.scene.json`
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="layout">
    <aside>
      <h1>PHPolygon Prototype</h1>
      <p class="hint">file-based · Vue + TresJS</p>

      <pre v-if="error" class="error">{{ error }}</pre>

      <template v-else-if="bundle">
        <label>Scene</label>
        <select v-model="selected">
          <option v-for="n in sceneNames" :key="n" :value="n">{{ n }}</option>
        </select>
        <p v-if="sceneNames.length === 0" class="hint">
          No scenes in the bundle. Add a <code>prototype.php</code> that returns Scene instances.
        </p>

        <button :disabled="!selected" @click="downloadScene">Download .scene.json</button>
        <p class="hint">
          Then transpile to canonical PHP:<br />
          <code>php bin/phpolygon scene:transpile that.scene.json --out src/Scene/X.php</code>
        </p>

        <hr />
        <p class="hint">
          {{ Object.keys(bundle.manifest.meshes).length }} meshes ·
          {{ bundle.manifest.materialIds.length }} materials
        </p>
      </template>

      <p v-else class="hint">Loading bundle…</p>
    </aside>

    <main>
      <SceneView
        v-if="bundle && selected"
        :key="selected"
        :bundle="bundle"
        :scene-name="selected"
      />
    </main>
  </div>
</template>

<style scoped>
.layout { display: grid; grid-template-columns: 300px 1fr; height: 100%; }
aside { padding: 18px; background: #1c1c26; border-right: 1px solid #2a2a3a; overflow: auto; }
main { position: relative; }
h1 { font-size: 16px; margin: 0 0 2px; }
.hint { font-size: 12px; color: #9a9ab0; line-height: 1.5; }
label { display: block; font-size: 12px; margin: 14px 0 4px; color: #c0c0d0; }
select, button { width: 100%; padding: 7px 8px; margin-bottom: 8px; border-radius: 6px;
  background: #26263a; color: #e0e0e6; border: 1px solid #34344a; font: inherit; }
button { cursor: pointer; }
button:disabled { opacity: 0.5; cursor: default; }
code { background: #26263a; padding: 1px 4px; border-radius: 4px; font-size: 11px; word-break: break-all; }
hr { border: none; border-top: 1px solid #2a2a3a; margin: 14px 0; }
.error { white-space: pre-wrap; color: #ff9a9a; font-size: 12px; }
</style>
