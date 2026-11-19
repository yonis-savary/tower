<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import ConnectDevicePanel from '../components/ConnectDevicePanel.vue'
import EmptyState from '../components/EmptyState.vue'
import { disconnectDevice } from '../client'
import { useDeviceSocketStore, type ConnectedDevice } from '../stores/deviceSocket'

type DevicesTab = 'connected' | 'connect'

const TABS: DevicesTab[] = ['connected', 'connect']

const { t, d } = useI18n()
const route = useRoute()
const router = useRouter()
const deviceSocket = useDeviceSocketStore()
const error = ref<string | null>(null)

// In the address, so that a tab can be linked to and survives a reload
const tab = computed<DevicesTab>({
  get: () => TABS.find((value) => value === route.query.tab) ?? 'connected',
  set: (value) => void router.replace({ query: { ...route.query, tab: value } }),
})

const icons = { desktop: 'pi-desktop', mobile: 'pi-mobile', unknown: 'pi-question-circle' }

function hostname(device: ConnectedDevice): string {
  return device.hostname ?? t('devices.unknownHost')
}

async function disconnect(device: ConnectedDevice) {
  if (!window.confirm(t('devices.disconnectConfirm', { name: hostname(device) }))) return

  error.value = null
  const { response } = await disconnectDevice({ path: { uuid: device.uuid } })
  if (!response?.ok) error.value = t('devices.disconnectError')
}
</script>

<template>
  <h1 class="page-title">{{ t('devices.title') }}</h1>

  <section class="panel">
    <Tabs v-model:value="tab" class="devices-tabs">
      <TabList>
        <Tab value="connected">{{ t('devices.connectedTab') }}</Tab>
        <Tab value="connect">{{ t('devices.connectTab') }}</Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="connected">
          <Message v-if="error" class="dialog-error" severity="error" :closable="false">{{ error }}</Message>

          <Message v-if="deviceSocket.status === 'disconnected'" severity="warn" :closable="false">
            {{ t('nav.socketDisconnected') }}
          </Message>

          <ul v-else-if="deviceSocket.devices.length" class="devices">
            <li v-for="device in deviceSocket.devices" :key="device.uuid">
              <span class="device-icon" role="img" :aria-label="t(`devices.type.${device.type}`)" :title="t(`devices.type.${device.type}`)">
                <!-- PrimeIcons has no laptop -->
                <svg v-if="device.type === 'laptop'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <rect x="4.5" y="5" width="15" height="10.5" rx="1.25" />
                  <path d="M2 19h20" />
                </svg>
                <i v-else :class="['pi', icons[device.type]]" aria-hidden="true" />
              </span>

              <div class="device-details">
                <strong>{{ hostname(device) }}</strong>
                <dl>
                  <dt>{{ t('devices.field.type') }}</dt>
                  <dd>{{ t(`devices.type.${device.type}`) }}</dd>
                  <dt>{{ t('devices.field.config') }}</dt>
                  <dd>{{ device.access_key }}</dd>
                  <template v-if="device.user_agent">
                    <dt>{{ t('devices.field.system') }}</dt>
                    <dd>{{ device.user_agent }}</dd>
                  </template>
                  <dt>{{ t('devices.field.connectedAt') }}</dt>
                  <dd>{{ d(new Date(device.connected_at), 'short') }}</dd>
                </dl>
              </div>

              <Button
                class="device-disconnect"
                :label="t('devices.disconnectButton')"
                severity="danger"
                text
                size="small"
                @click="disconnect(device)"
              />
            </li>
          </ul>

          <EmptyState v-else icon="pi pi-desktop" :title="t('devices.emptyTitle')" :hint="t('devices.emptyHint')" compact />
        </TabPanel>
        <TabPanel value="connect">
          <ConnectDevicePanel />
        </TabPanel>
      </TabPanels>
    </Tabs>
  </section>
</template>

<style scoped>
.page-title {
  margin: 0 0 1.25rem;
  font-size: 1.5rem;
  font-weight: 600;
}

.panel {
  max-width: 80rem;
  margin-bottom: 1.25rem;
  padding: 1.25rem;
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.devices-tabs :deep(.p-tabpanels) {
  padding: 1rem 0 0;
  background: transparent;
}

.dialog-error {
  margin-bottom: 1rem;
}

.devices {
  margin: 0;
  padding: 0;
  list-style: none;
}

.devices li {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.5rem 0.875rem;
  padding: 0.875rem 0;
  border-top: 1px solid var(--p-content-border-color);
}

.devices li:first-child {
  padding-top: 0;
  border-top: 0;
}

.device-icon {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--p-content-border-radius);
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
}

.device-icon i {
  font-size: 1.25rem;
}

.device-icon svg {
  width: 1.5rem;
  height: 1.5rem;
}

.device-details {
  flex: 1 1 14rem;
  min-width: 0;
}

.device-details dl {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0.125rem 0.75rem;
  margin: 0.25rem 0 0;
  font-size: 0.875rem;
}

.device-details dt {
  color: var(--p-text-muted-color);
}

.device-details dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.device-disconnect {
  flex: none;
  margin-left: auto;
}
</style>
