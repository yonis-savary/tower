<script setup lang="ts">
import { computed } from 'vue'
import PullRequestTreeNode from './PullRequestTreeNode.vue'
import type { ListPullRequestApprovalsResponse, ListPullRequestsResponse } from '../client'

type PullRequest = ListPullRequestsResponse[number]
type PullRequestNode = { kind: 'pull-request'; pullRequest: PullRequest; children: TreeNode[]; row: number; col: number }
type BranchNode = { kind: 'branch'; branch: string; children: TreeNode[]; row: number; col: number }
type TreeNode = PullRequestNode | BranchNode
type Point = { x: number; y: number }

const props = defineProps<{ pullRequests: PullRequest[]; approvals: ListPullRequestApprovalsResponse }>()

const NODE_SIZE = 44
const LABEL_WIDTH = 120
const COLUMN_WIDTH = 150
const ROW_HEIGHT = 104
const ROW_TOP = 16
// Labels are wider than the circles they sit under : the outer columns need room for them
const PADDING_X = (LABEL_WIDTH - NODE_SIZE) / 2

// A pull request is the child of the one whose source branch it targets. The roots are grouped under
// the branch they target, so several chains merging into the same branch share one branch node
function buildForest(pullRequests: PullRequest[]): BranchNode[] {
  const bySource = new Map(pullRequests.map((pullRequest) => [pullRequest.source_branch, pullRequest]))
  const nodes = new Map<number, PullRequestNode>(
    pullRequests.map((pullRequest) => [pullRequest.id, { kind: 'pull-request', pullRequest, children: [], row: 0, col: 0 }]),
  )

  const parents = new Map<number, number>()
  pullRequests.forEach(({ id, target_branch }) => {
    const parent = bySource.get(target_branch)
    if (parent && parent.id !== id) parents.set(id, parent.id)
  })

  // Branches targeting each other would loop forever : the link closing the loop is cut
  for (const start of [...parents.keys()]) {
    const seen = new Set<number>()
    let current = start
    while (parents.has(current)) {
      if (seen.has(current)) {
        parents.delete(start)
        break
      }
      seen.add(current)
      current = parents.get(current)!
    }
  }

  const branches = new Map<string, BranchNode>()
  pullRequests.forEach(({ id, target_branch }) => {
    const node = nodes.get(id)!
    const parent = parents.has(id) ? nodes.get(parents.get(id)!) : undefined
    if (parent) {
      parent.children.push(node)
      return
    }

    if (!branches.has(target_branch)) branches.set(target_branch, { kind: 'branch', branch: target_branch, children: [], row: 0, col: 0 })
    branches.get(target_branch)!.children.push(node)
  })

  return [...branches.values()]
}

// The depth is the column. A first child stays on the row of its parent, the others start a new row
// below : a stack of pull requests stays on one line, and a fork fans out downwards
function placeForest(roots: TreeNode[]): number {
  let nextRow = 0

  const place = (node: TreeNode, col: number) => {
    node.col = col
    if (!node.children.length) {
      node.row = nextRow++
      return
    }
    node.children.forEach((child) => place(child, col + 1))
    node.row = node.children[0]!.row
  }

  roots.forEach((root, index) => {
    if (index > 0) nextRow++
    place(root, 0)
  })

  return nextRow
}

function flatten(roots: TreeNode[]): TreeNode[] {
  return roots.flatMap((node) => [node, ...flatten(node.children)])
}

function center(node: TreeNode): Point {
  return { x: PADDING_X + node.col * COLUMN_WIDTH + NODE_SIZE / 2, y: ROW_TOP + node.row * ROW_HEIGHT + NODE_SIZE / 2 }
}

function edgePath(parent: Point, child: Point): string {
  const from = parent.x + NODE_SIZE / 2
  const to = child.x - NODE_SIZE / 2
  const middle = (from + to) / 2
  return `M ${from},${parent.y} C ${middle},${parent.y} ${middle},${child.y} ${to},${child.y}`
}

function nodeId(node: TreeNode): string {
  return node.kind === 'pull-request' ? `pr-${node.pullRequest.id}` : `branch-${node.branch}`
}

const layout = computed(() => {
  const roots = buildForest(props.pullRequests)
  const rows = placeForest(roots)
  const nodes = flatten(roots)
  const columns = Math.max(0, ...nodes.map(({ col }) => col)) + 1

  return {
    width: 2 * PADDING_X + (columns - 1) * COLUMN_WIDTH + NODE_SIZE,
    height: Math.max(rows, 1) * ROW_HEIGHT,
    nodes: nodes.map((node) => {
      const { x, y } = center(node)
      return { id: nodeId(node), node, left: x - LABEL_WIDTH / 2, top: y - NODE_SIZE / 2 }
    }),
    edges: nodes.flatMap((parent) =>
      parent.children.map((child) => ({ id: `${nodeId(parent)}--${nodeId(child)}`, path: edgePath(center(parent), center(child)) })),
    ),
  }
})

function reviewersOf(id: number) {
  return props.approvals.filter(({ pull_request }) => pull_request === id)
}
</script>

<template>
  <div class="tree-scroll">
    <div class="tree" :style="{ width: `${layout.width}px`, height: `${layout.height}px` }">
      <svg class="tree-edges" :width="layout.width" :height="layout.height" aria-hidden="true">
        <path v-for="edge in layout.edges" :key="edge.id" :d="edge.path" />
      </svg>

      <div
        v-for="{ id, node, left, top } in layout.nodes"
        :key="id"
        class="tree-node"
        :style="{ left: `${left}px`, top: `${top}px`, width: `${LABEL_WIDTH}px` }"
      >
        <PullRequestTreeNode v-if="node.kind === 'pull-request'" :pull-request="node.pullRequest" :reviewers="reviewersOf(node.pullRequest.id)" />
        <div v-else class="branch-node">
          <span class="branch-circle"><i class="pi pi-sitemap" aria-hidden="true" /></span>
          <small :title="node.branch">{{ node.branch }}</small>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tree-scroll {
  overflow-x: auto;
  padding-bottom: 0.25rem;
}

.tree {
  position: relative;
}

.tree-edges {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.tree-edges path {
  fill: none;
  stroke: var(--p-content-border-color);
  stroke-width: 2;
}

.tree-node {
  position: absolute;
}

.branch-node {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.375rem;
}

.branch-circle {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  color: var(--p-text-muted-color);
  border: 2px dashed currentColor;
  border-radius: 50%;
}

.branch-node small {
  max-width: 100%;
  overflow: hidden;
  color: var(--p-text-muted-color);
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
