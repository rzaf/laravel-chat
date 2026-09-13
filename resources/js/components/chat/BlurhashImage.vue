<!-- Components/BlurhashImage.vue -->


<script setup>
import { ref, watch, onMounted } from 'vue';
import { decode } from 'blurhash';

const props = defineProps({
  hash: { type: String, required: true },
  alt: { type: String, default: '' },
  width: { type: Number, default: 32 },  // Low resolution is best for rendering fast
  height: { type: Number, default: 32 }
});

const canvasRef = ref(null);
const isLoaded = ref(false);

const renderBlurhash = () => {
  if (!props.hash || !canvasRef.value) return;

  try {
    const pixels = decode(props.hash, props.width, props.height);
    const ctx = canvasRef.value.getContext('2d');
    
    if (ctx) {
      const imageData = ctx.createImageData(props.width, props.height);
      imageData.data.set(pixels);
      ctx.putImageData(imageData, 0, 0);
    }
  } catch (error) {
    console.error("Failed to decode blurhash:", error);
  }
};

// Re-render if hash changes dynamically
watch(() => props.hash, renderBlurhash);

onMounted(renderBlurhash);
</script>


<template>
  <div class="relative w-full h-full">
    <!-- Blurhash Placeholder Canvas -->
    <canvas 
      ref="canvasRef" 
      :width="width" 
      :height="height"
      class="absolute inset-0 w-full h-full object-cover transition-opacity duration-500"
      :class="{ 'opacity-0 pointer-events-none': isLoaded }"
    />

  </div>
</template>