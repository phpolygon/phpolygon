<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Bundle } from './runtime/bundle'
import type { SceneJson } from './runtime/types'
import { saveSceneJson } from './authoring/saveScene'
import SceneView from './components/SceneView.vue'
import scratchpad from './playground/scene'

type Mode = 'bundle' | 'scratchpad'

const bundle = ref<Bundle | null>(null)
const mode = ref<Mode>('bundle')
const selectedName = ref<string>('')
const currentScene = ref<SceneJson | null>(null)
const error = ref<string>('')

const baseUrl = (import.meta.env.VITE_BUNDLE_URL as string | undefined) ?? '/bundle'
const sceneNames = computed(() => bundle.value?.sceneNames() ?? [])
const viewKey = computed(() => `${mode.value}:${selectedName.value}`)

onMounted(async () => {
  try {
    bundle.value = await Bundle.load(baseUrl)
  } catch (e) {
    error.value =
      `Could not load bundle from ${baseUrl}. Export it first:\n` +
      `php bin/phpolygon prototype:export --out tools/prototype/public/bundle\n(${e})`
    return
  }
  if (sceneNames.value.length > 0) {
    await selectBundleScene(sceneNames.value[0])
  } else {
    selectScratchpad()
  }
})

async function selectBundleScene(name: string): Promise<void> {
  if (!bundle.value) return
  mode.value = 'bundle'
  selectedName.value = name
  currentScene.value = await bundle.value.loadScene(name)
}

function selectScratchpad(): void {
  mode.value = 'scratchpad'
  selectedName.value = scratchpad.name
  currentScene.value = scratchpad
}

async function save(): Promise<void> {
  if (currentScene.value) await saveSceneJson(currentScene.value)
}
</script>

<template>
  <div class="layout">
    <aside>
      <h1>PHPolygon Prototype</h1>
      <p class="hint">file-based · Vue + TresJS</p>

      <pre v-if="error" class="error">{{ error }}</pre>

      <template v-else-if="bundle">
        <div class="tabs">
          <button :class="{ active: mode === 'scratchpad' }" @click="selectScratchpad">Scratchpad</button>
          <button
            :class="{ active: mode === 'bundle' }"
            :disabled="sceneNames.length === 0"
            @click="sceneNames.length && selectBundleScene(selectedName || sceneNames[0])"
          >Bundle</button>
        </div>

        <template v-if="mode === 'bundle'">
          <label>Scene</label>
          <select :value="selectedName" @change="selectBundleScene(($event.target as HTMLSelectElement).value)">
            <option v-for="n in sceneNames" :key="n" :value="n">{{ n }}</option>
          </select>
          <p v-if="sceneNames.length === 0" class="hint">
            No scenes in the bundle. Add a <code>prototype.php</code> that returns Scene instances.
          </p>
        </template>

        <template v-else>
          <p class="hint">Editing <code>src/playground/scene.ts</code> (typed builders, generated from the engine schema).</p>
        </template>

        <button :disabled="!currentScene" @click="save">Save scene (.scene.json)</button>
        <p class="hint">
          Then transpile to canonical PHP:<br />
          <code>php bin/phpolygon scene:transpile {{ selectedName }}.scene.json --out src/Scene/X.php</code>
        </p>

        <hr />
        <p class="hint">
          {{ Object.keys(bundle.manifest.meshes).length }} meshes ·
          {{ bundle.manifest.materialIds.length }} materials ·
          {{ bundle.componentCount() }} components
        </p>
      </template>

      <p v-else class="hint">Loading bundle…</p>
    </aside>

    <main>
      <SceneView
        v-if="bundle && currentScene"
        :key="viewKey"
        :bundle="bundle"
        :scene="currentScene"
      />
    </main>
  </div>
</template>

<style scoped>
.layout { display: grid; grid-template-columns: 320px 1fr; height: 100%; }
aside { padding: 18px; background: #1c1c26; border-right: 1px solid #2a2a3a; overflow: auto; }
main { position: relative; }
h1 { font-size: 16px; margin: 0 0 2px; }
.hint { font-size: 12px; color: #9a9ab0; line-height: 1.5; }
label { display: block; font-size: 12px; margin: 14px 0 4px; color: #c0c0d0; }
.tabs { display: flex; gap: 6px; margin: 12px 0; }
.tabs button { flex: 1; }
select, button { width: 100%; padding: 7px 8px; margin-bottom: 8px; border-radius: 6px;
  background: #26263a; color: #e0e0e6; border: 1px solid #34344a; font: inherit; }
button { cursor: pointer; }
button.active { background: #3a3a5a; border-color: #4a4a6a; }
button:disabled { opacity: 0.5; cursor: default; }
code { background: #26263a; padding: 1px 4px; border-radius: 4px; font-size: 11px; word-break: break-all; }
hr { border: none; border-top: 1px solid #2a2a3a; margin: 14px 0; }
.error { white-space: pre-wrap; color: #ff9a9a; font-size: 12px; }
</style>
