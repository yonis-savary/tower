import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useWorkspaceStore, type WorkspaceSection } from '../stores/workspace'

export const NOTES_SHORTCUT = 'n'
export const NOTES_QUERY = 'notes'

export type GlobalShortcut = { key: string; label: string; section: WorkspaceSection }

export const GLOBAL_SHORTCUTS: GlobalShortcut[] = [
  { key: NOTES_SHORTCUT, label: 'tasks.notes.open', section: 'tasks' },
]

function isTyping(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  return target.isContentEditable || target.closest('input, textarea, select, [role="dialog"]') !== null
}

export function useGlobalShortcuts() {
  const router = useRouter()
  const workspace = useWorkspaceStore()

  function onKeydown(event: KeyboardEvent) {
    if (event.key.toLowerCase() !== NOTES_SHORTCUT || event.repeat) return
    if (event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return
    if (isTyping(event.target) || !workspace.hasSection('tasks')) return

    event.preventDefault()
    void router.push({ name: 'tasks', query: { [NOTES_QUERY]: '1' } })
  }

  onMounted(() => window.addEventListener('keydown', onKeydown))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
}
