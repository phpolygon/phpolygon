<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { TresCanvas } from '@tresjs/core'
import { OrbitControls } from '@tresjs/cientos'
import * as THREE from 'three'
import type { Bundle } from '../runtime/bundle'
import { buildSceneObject } from '../runtime/sceneBuilder'
import type { SceneJson } from '../runtime/types'

const props = defineProps<{ bundle: Bundle; scene: SceneJson }>()

const root = ref<THREE.Object3D | null>(null)

function rebuild(): void {
  root.value = buildSceneObject(props.scene, props.bundle)
}

watch(() => props.scene, rebuild, { deep: true })
onMounted(rebuild)
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
