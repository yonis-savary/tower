<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import AutomationSchedules from '../components/AutomationSchedules.vue'

const TABS = ['schedules'] as const
type AutomationTab = (typeof TABS)[number]

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

// In the address, so that a tab can be linked to and survives a reload
const tab = computed<AutomationTab>({
  get: () => TABS.find((value) => value === route.query.tab) ?? 'schedules',
  set: (value) => void router.replace({ query: { ...route.query, tab: value } }),
})
</script>

<template>
  <h1 class="page-title">{{ t('automation.title') }}</h1>

  <Tabs v-model:value="tab" class="automation-tabs">
    <TabList>
      <Tab value="schedules">{{ t('automation.tabs.schedules') }}</Tab>
    </TabList>
    <TabPanels>
      <TabPanel value="schedules">
        <AutomationSchedules />
      </TabPanel>
    </TabPanels>
  </Tabs>
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.automation-tabs {
  min-width: 0;
}

.automation-tabs :deep(.p-tabpanels) {
  padding: 1rem 0 0;
  background: transparent;
}
</style>
