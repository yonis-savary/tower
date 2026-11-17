import { defineStore } from 'pinia'
import { ref } from 'vue'
import { suggestEventTypes } from '../client'

export type SuggestionSource = 'quick_action' | 'webhook' | 'schedule' | 'history'

/** The order of the groups under an event field, after the events of Tower */
export const SUGGESTION_SOURCES: SuggestionSource[] = ['quick_action', 'webhook', 'schedule', 'history']

/**
 * The event types already used in the current workspace, by source. Loaded again by each event
 * field that appears, so that a type created a moment ago is suggested.
 */
export const useEventSuggestionsStore = defineStore('eventSuggestions', () => {
  const bySource = ref<Partial<Record<SuggestionSource, string[]>>>({})
  let loading: Promise<void> | null = null

  function load(): Promise<void> {
    loading ??= suggestEventTypes()
      .then(({ data }) => {
        bySource.value = Object.fromEntries((data ?? []).map(({ source, types }) => [source, types ?? []]))
      })
      .finally(() => (loading = null))

    return loading
  }

  return { bySource, load }
})
