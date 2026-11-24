import { ref, watch, type Ref } from 'vue'

// null : nothing chosen yet, so that the caller can apply its own default
export function storedIds(key: Ref<string>): Ref<number[] | null> {
  const ids = ref<number[] | null>(null)

  watch(key, (current) => {
    try {
      const stored = JSON.parse(localStorage.getItem(current) ?? 'null')
      ids.value = Array.isArray(stored) ? stored.filter((id): id is number => typeof id === 'number') : null
    } catch {
      ids.value = null
    }
  }, { immediate: true })

  watch(ids, (value) => {
    try {
      if (value !== null) localStorage.setItem(key.value, JSON.stringify(value))
    } catch {
      // Private browsing or blocked storage : the choice only lasts for this page
    }
  })

  return ids
}
