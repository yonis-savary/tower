import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  closeSprint,
  createSprint,
  listSprints,
  listTasks,
  moveTask,
  openSprint,
  type ListSprintsResponse,
  type ListTasksResponse,
} from '../client'

export type Task = Omit<ListTasksResponse[number], 'status'> & { status: TaskStatus }
export type Sprint = ListSprintsResponse[number]
export type TaskStatus = 'todo' | 'in_progress' | 'in_review' | 'finalizing' | 'done' | 'cancelled'

/** The columns of the board : a cancelled task is kept apart */
export const BOARD_STATUSES: TaskStatus[] = ['todo', 'in_progress', 'in_review', 'finalizing', 'done']
export const TASK_STATUSES: TaskStatus[] = [...BOARD_STATUSES, 'cancelled']

export const useTasksStore = defineStore('tasks', () => {
  const tasks = ref<Task[]>([])
  const sprints = ref<Sprint[]>([])
  const failed = ref(false)

  const activeSprint = computed(() => sprints.value.find(({ state }) => state === 'active') ?? null)

  async function load(): Promise<void> {
    const [taskList, sprintList] = await Promise.all([listTasks(), listSprints()])
    failed.value = !taskList.data || !sprintList.data
    if (taskList.data) tasks.value = taskList.data as Task[]
    if (sprintList.data) sprints.value = sprintList.data
  }

  /** The server decides the final state : a refused move puts the task back too */
  async function move(id: number, sprint: number | null, status: TaskStatus, ordered?: number[]): Promise<boolean> {
    const { response } = await moveTask({ path: { task: id }, body: { sprint, status, ordered } })
    await load()
    return !!response?.ok
  }

  async function addSprint(name: string): Promise<boolean> {
    const { response } = await createSprint({ body: { name } })
    await load()
    return !!response?.ok
  }

  async function open(sprint: number): Promise<boolean> {
    const { response } = await openSprint({ path: { sprint } })
    await load()
    return !!response?.ok
  }

  async function close(sprint: number): Promise<boolean> {
    const { response } = await closeSprint({ path: { sprint } })
    await load()
    return !!response?.ok
  }

  return { tasks, sprints, failed, activeSprint, load, move, addSprint, open, close }
})
