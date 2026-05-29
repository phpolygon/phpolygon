import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'

// TresJS registers <Tres*> tags as custom elements; tell the Vue compiler not
// to treat them as Vue components.
export default defineConfig({
  plugins: [
    vue({
      template: {
        compilerOptions: {
          isCustomElement: (tag) => tag.startsWith('Tres'),
        },
      },
    }),
    vueJsx(),
  ],
  server: {
    // The static bundle written by `bin/phpolygon prototype:export` is served
    // from /bundle (symlink or copy .phpolygon/prototype here, or set
    // VITE_BUNDLE_URL). The browser only ever reads these static files.
    fs: { allow: ['..', '../..'] },
  },
})
