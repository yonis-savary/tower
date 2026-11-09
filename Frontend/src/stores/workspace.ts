import { defineStore } from 'pinia'
import { ref } from 'vue'
import {
  getCurrentWorkspace,
  listWorkspaces,
  selectWorkspace,
  type GetCurrentWorkspaceResponse,
  type ListWorkspacesResponse,
} from '../client'

export const useWorkspaceStore = defineStore('workspace', () => {
  const current = ref<GetCurrentWorkspaceResponse | null>(null)
  // `null` until the first answer
  const workspaces = ref<ListWorkspacesResponse | null>(null)

  /** The selection lives in the server session : this restores it after a reload or a login */
  async function loadCurrent(): Promise<void> {
    const { data } = await getCurrentWorkspace()
    current.value = data ?? null
  }

  async function loadWorkspaces(): Promise<void> {
    const { data } = await listWorkspaces()
    if (data) workspaces.value = data
  }

  async function select(workspace: number): Promise<void> {
    if (workspace === current.value?.id) return

    const { data } = await selectWorkspace({ body: { workspace } })
    if (data) current.value = data
  }

  function clear(): void {
    current.value = null
    workspaces.value = null
  }

  return { current, workspaces, loadCurrent, loadWorkspaces, select, clear }
})
