<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { TresCanvas } from '@tresjs/core'
import { OrbitControls } from '@tresjs/cientos'
import * as THREE from 'three'
import type { Bundle } from '../runtime/bundle'
import { buildSceneObject } from '../runtime/sceneBuilder'

const props = defineProps<{ bundle: Bundle; sceneName: string }>()

const root = ref<THREE.Object3D | null>(null)

async function load(): Promise<void> {
  root.value = null
  const scene = await props.bundle.loadScene(props.sceneName)
  root.value = buildSceneObject(scene, props.bundle)
}

watch(() => props.sceneName, load)
onMounted(load)
</script>

<template>
  <TresCanvas clear-color="#16161e" window-size>
    <TresPerspectiveCamera :position="[10, 8, 14]" :fov="55" :look-at="[0, 1, 0]" />
    <OrbitControls :target="[0, 1, 0]" />

    <TresAmbientLight :intensity="0.55" />
    <TresDirectionalLight :position="[6, 12, 8]" :intensity="1.3" />
    <TresGridHelper :args="[100, 100, 0x444455, 0x2a2a3a]" />

    <primitive v-if="root" :object="root" />
  </TresCanvas>
</template>
