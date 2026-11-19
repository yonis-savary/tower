<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import AutomationNotifications from '../components/AutomationNotifications.vue'
import AutomationSchedules from '../components/AutomationSchedules.vue'
import { usePushStore } from '../stores/push'

const TABS = ['schedules', 'notifications'] as const
type AutomationTab = (typeof TABS)[number]

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const push = usePushStore()

// In the address, so that a tab can be linked to and survives a reload
const tab = computed<AutomationTab>({
  get: () => TABS.find((value) => value === route.query.tab) ?? 'schedules',
  set: (value) => void router.replace({ query: { ...route.query, tab: value } }),
})

// Without VAPID keys on the server, there is nothing to configure here
watch(
  () => [tab.value, push.configured] as const,
  ([value, configured]) => {
    if (value === 'notifications' && configured === false) tab.value = 'schedules'
  },
  { immediate: true },
)
</script>

<template>
  <h1 class="page-title">{{ t('automation.title') }}</h1>

  <Tabs v-model:value="tab" class="automation-tabs">
    <TabList>
      <Tab value="schedules">{{ t('automation.tabs.schedules') }}</Tab>
      <Tab v-if="push.configured" value="notifications">{{ t('automation.tabs.notifications') }}</Tab>
    </TabList>
    <TabPanels>
      <TabPanel value="schedules">
        <AutomationSchedules />
      </TabPanel>
      <TabPanel v-if="push.configured" value="notifications">
        <AutomationNotifications />
      </TabPanel>
    </TabPanels>
  </Tabs>
</template>

<style scoped>
/* Down to the bottom of the page, so that an empty tab centers its empty state in it */
.automation-tabs,
.automation-tabs :deep(.p-tabpanels),
.automation-tabs :deep(.p-tabpanel) {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.automation-tabs :deep(.p-tabpanels) {
  padding: 1rem 0 0;
  background: transparent;
}
</style>
