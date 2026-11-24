import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useWorkspaceStore, type WorkspaceSection } from '../stores/workspace'

export const NOTES_SHORTCUT = 'n'
export const NOTES_QUERY = 'notes'
export const SEARCH_SHORTCUT = 'f'

// One search for the whole page, opened by its shortcut from any screen
export const searchOpen = ref(false)

export type GlobalShortcut = { key: string; label: string; section?: WorkspaceSection }

export const GLOBAL_SHORTCUTS: GlobalShortcut[] = [
  { key: SEARCH_SHORTCUT, label: 'search.open' },
  { key: NOTES_SHORTCUT, label: 'tasks.notes.open', section: 'tasks' },
]

function isTyping(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  return target.isContentEditable || target.closest('input, textarea, select, [role="dialog"]') !== null
}

export function useGlobalShortcuts() {
  const router = useRouter()
  const workspace = useWorkspaceStore()

  const actions: Record<string, () => void> = {
    [NOTES_SHORTCUT]: () => void router.push({ name: 'tasks', query: { [NOTES_QUERY]: '1' } }),
    [SEARCH_SHORTCUT]: () => (searchOpen.value = true),
  }

  function onKeydown(event: KeyboardEvent) {
    if (event.repeat || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || isTyping(event.target)) return

    const shortcut = GLOBAL_SHORTCUTS.find(({ key }) => key === event.key.toLowerCase())
    if (!shortcut || (shortcut.section && !workspace.hasSection(shortcut.section))) return

    event.preventDefault()
    actions[shortcut.key]?.()
  }

  onMounted(() => window.addEventListener('keydown', onKeydown))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
}
