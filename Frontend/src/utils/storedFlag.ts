import { ref, watch, type Ref } from 'vue'

export function storedFlag(key: Ref<string>): Ref<boolean> {
  const flag = ref(false)

  watch(key, (current) => {
    try {
      flag.value = localStorage.getItem(current) === '1'
    } catch {
      flag.value = false
    }
  }, { immediate: true })

  watch(flag, (value) => {
    try {
      localStorage.setItem(key.value, value ? '1' : '0')
    } catch {
      // Private browsing or blocked storage : the choice only lasts for this page
    }
  })

  return flag
}
