<?php

namespace App\Domain\Integrations\Jira;

class JiraProvider
{
    private const PAGE_LENGTH = 100;
    private const FIELDS = 'summary,status,issuetype,priority,project,sprint,assignee,subtasks';
    private const CONTENT_FIELDS = self::FIELDS.',description,assignee,reporter,labels,comment,attachment';
    private const CONTENT_COMMENTS = 20;
    private const WORKED_FIELDS = 'summary,status,issuetype,project,created,resolutiondate';
    private const BOARD_JQL = '{filter}statusCategory != Done{exclusion} ORDER BY updated DESC';

    private const CATEGORIES = [
        'new' => JiraIssueData::CATEGORY_TODO,
        'indeterminate' => JiraIssueData::CATEGORY_IN_PROGRESS,
        'done' => JiraIssueData::CATEGORY_DONE,
    ];

    public function __construct(
        private readonly JiraClient $client,
    ) {}

    /**
     * @param list<string> $excludedStatusIds
     *
     * @return list<JiraIssueData>
     */
    public function boardIssues(int $board, ?string $jql = null, array $excludedStatusIds = []): array
    {
        $path = "/rest/agile/1.0/board/{$board}/issue";
        $issues = [];
        // Status ids come from Jira's board configuration, they are numbers : nothing else may enter the query
        $ids = array_filter($excludedStatusIds, fn (string $id) => ctype_digit($id));
        $exclusion = [] === $ids ? '' : ' AND status NOT IN ('.implode(', ', $ids).')';
        $filter = null === $jql ? '' : "({$jql}) AND ";
        $parameters = ['jql' => str_replace(['{filter}', '{exclusion}'], [$filter, $exclusion], self::BOARD_JQL), 'fields' => self::FIELDS, 'maxResults' => self::PAGE_LENGTH, 'startAt' => 0];

        while (true) {
            $page = $this->client->read($path, $parameters) ?? throw new \RuntimeException("Jira does not know the board {$board}");
            $values = $page['issues'] ?? [];
            array_push($issues, ...$values);

            $parameters['startAt'] += count($values);
            if ([] === $values || $parameters['startAt'] >= ($page['total'] ?? 0))
                break;
        }

        $assignees = $this->subtaskAssignees(array_merge([], ...array_map(fn (array $issue) => array_column($issue['fields']['subtasks'] ?? [], 'id'), $issues)));

        return array_map(fn (array $issue) => $this->toData($issue, $assignees), $issues);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string,?array> The assignee field of each subtask, by subtask id
     */
    private function subtaskAssignees(array $ids): array
    {
        $assignees = [];
        // Issue ids come from Jira, they are numbers : nothing else may enter the query
        $ids = array_filter(array_unique(array_map('strval', $ids)), fn (string $id) => ctype_digit($id));

        foreach (array_chunk($ids, self::PAGE_LENGTH) as $chunk) {
            $parameters = ['jql' => 'id IN ('.implode(', ', $chunk).')', 'fields' => 'assignee', 'maxResults' => self::PAGE_LENGTH];
            $page = $this->client->read('/rest/api/3/search/jql', $parameters) ?? [];
            foreach ($page['issues'] ?? [] as $issue)
                $assignees[(string) $issue['id']] = $issue['fields']['assignee'] ?? null;
        }

        return $assignees;
    }

    /**
     * The issues the account of the token was assigned to during a period, and whose status changed
     * during it, with every status change Jira kept.
     *
     * @param string $start first second of the period, Y-m-d H:i:s in server time
     * @param string $end last second of the period, Y-m-d H:i:s in server time
     *
     * @return array{issues:list<JiraWorkedIssue>,truncated:bool} truncated beyond one page, the most recently updated kept
     */
    public function workedIssues(string $start, string $end): array
    {
        // Formatted from dates : nothing else enters the query. Jira reads them in the timezone of the account
        $period = sprintf('("%s", "%s")', (new \DateTimeImmutable($start))->format('Y/m/d H:i'), (new \DateTimeImmutable($end))->format('Y/m/d H:i'));
        $page = $this->client->read('/rest/api/3/search/jql', [
            'jql' => "assignee WAS currentUser() DURING {$period} AND status CHANGED DURING {$period} ORDER BY updated DESC",
            'fields' => self::WORKED_FIELDS,
            'expand' => 'changelog',
            'maxResults' => self::PAGE_LENGTH,
        ]) ?? [];

        return [
            'issues' => array_map(fn (array $issue) => $this->toWorkedIssue($issue), $page['issues'] ?? []),
            'truncated' => !($page['isLast'] ?? true),
        ];
    }

    /**
     * @return array<string,string> The category of every status of the site, by status id
     */
    public function statusCategories(): array
    {
        $categories = [];
        foreach ($this->client->read('/rest/api/3/status') ?? [] as $status)
            $categories[(string) $status['id']] = $this->category($status['statusCategory']['key'] ?? null);

        return $categories;
    }

    private function toWorkedIssue(array $issue): JiraWorkedIssue
    {
        $fields = $issue['fields'] ?? [];
        $key = (string) $issue['key'];

        $changes = [];
        foreach ($issue['changelog']['histories'] ?? [] as $history) {
            foreach ($history['items'] ?? [] as $item) {
                if ('status' !== ($item['field'] ?? null) || null === ($at = self::atom($history['created'] ?? null)))
                    continue;

                $changes[] = [
                    'at' => $at,
                    'from' => $item['fromString'] ?? null,
                    'to' => $item['toString'] ?? null,
                    'from_id' => isset($item['from']) ? (string) $item['from'] : null,
                    'to_id' => isset($item['to']) ? (string) $item['to'] : null,
                ];
            }
        }
        usort($changes, fn (array $a, array $b) => strtotime($a['at']) <=> strtotime($b['at']));

        return new JiraWorkedIssue(
            key: $key,
            summary: (string) ($fields['summary'] ?? ''),
            url: $this->client->site.'/browse/'.rawurlencode($key),
            project: (string) ($fields['project']['name'] ?? ''),
            issueType: (string) ($fields['issuetype']['name'] ?? ''),
            statusId: (string) ($fields['status']['id'] ?? ''),
            status: (string) ($fields['status']['name'] ?? ''),
            statusCategory: $this->category($fields['status']['statusCategory']['key'] ?? null),
            created: self::atom($fields['created'] ?? null),
            resolved: self::atom($fields['resolutiondate'] ?? null),
            changes: $changes,
        );
    }

    private static function atom(?string $date): ?string
    {
        if (null === $date || '' === $date)
            return null;

        try {
            return (new \DateTimeImmutable($date))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    public function myself(): JiraUser
    {
        $user = $this->client->read('/rest/api/3/myself') ?? throw new \RuntimeException('Jira does not know the account of this token');

        return $this->toUser($user);
    }

    /**
     * @return list<JiraColumn>
     */
    public function boardColumns(int $board): array
    {
        $configuration = $this->client->read("/rest/agile/1.0/board/{$board}/configuration")
            ?? throw new \RuntimeException("Jira does not know the board {$board}");

        return array_map(fn (array $column) => new JiraColumn(
            name: (string) $column['name'],
            statusIds: array_map(fn (array $status) => (string) $status['id'], $column['statuses'] ?? []),
        ), $configuration['columnConfig']['columns'] ?? []);
    }

    public function boardName(int $board): string
    {
        $found = $this->client->read("/rest/agile/1.0/board/{$board}") ?? throw new \RuntimeException("Jira does not know the board {$board}");

        return (string) ($found['name'] ?? '');
    }

    public function issue(string $key): ?JiraIssueData
    {
        // The agile API, unlike /rest/api/3, gives the sprint as a named field
        $issue = $this->client->read('/rest/agile/1.0/issue/'.rawurlencode($key), ['fields' => self::FIELDS]);

        return $issue ? $this->toData($issue) : null;
    }

    public function issueContent(string $key): ?JiraIssueContent
    {
        $issue = $this->client->read('/rest/agile/1.0/issue/'.rawurlencode($key), ['fields' => self::CONTENT_FIELDS]);
        if (!$issue)
            return null;

        // API v2 gives the description and the comments as wiki text, v3 and the agile API as an ADF document
        $text = $this->client->read('/rest/api/2/issue/'.rawurlencode($key), ['fields' => 'description,comment'])['fields'] ?? [];
        $fields = $issue['fields'] ?? [];
        $comments = array_slice($text['comment']['comments'] ?? [], -self::CONTENT_COMMENTS);

        return new JiraIssueContent(
            issue: $this->toData($issue),
            description: (string) ($text['description'] ?? ''),
            assignee: $fields['assignee']['displayName'] ?? null,
            reporter: $fields['reporter']['displayName'] ?? null,
            labels: array_values($fields['labels'] ?? []),
            comments: array_map(fn (array $comment) => [
                'author' => (string) ($comment['author']['displayName'] ?? ''),
                'created' => (string) ($comment['created'] ?? ''),
                'body' => (string) ($comment['body'] ?? ''),
            ], $comments),
            attachments: array_map(fn (array $attachment) => $this->toAttachment($attachment), $fields['attachment'] ?? []),
        );
    }

    public function attachment(string $id): ?JiraAttachment
    {
        $attachment = $this->client->read('/rest/api/3/attachment/'.rawurlencode($id));

        return $attachment ? $this->toAttachment($attachment) : null;
    }

    public function attachmentContent(string $id): string
    {
        $path = '/rest/api/3/attachment/content/'.rawurlencode($id);
        $response = $this->client->get($path, headers: ['Accept' => '*/*']);
        if (200 !== $response->getStatusCode())
            throw new \RuntimeException($this->client->describeFailure($response, $path));

        return $response->getBody();
    }

    /**
     * @return list<JiraTransition>
     */
    public function transitions(string $key): array
    {
        $transitions = $this->client->read('/rest/api/3/issue/'.rawurlencode($key).'/transitions')['transitions'] ?? [];

        return array_map(fn (array $transition) => new JiraTransition(
            id: (string) $transition['id'],
            name: (string) $transition['name'],
            statusId: (string) ($transition['to']['id'] ?? ''),
            status: (string) ($transition['to']['name'] ?? ''),
            statusCategory: $this->category($transition['to']['statusCategory']['key'] ?? null),
        ), $transitions);
    }

    /**
     * @return ?string Jira's refusal, null once applied
     */
    public function transition(string $key, string $transitionId): ?string
    {
        $path = '/rest/api/3/issue/'.rawurlencode($key).'/transitions';
        $response = $this->client->postJson($path, ['transition' => ['id' => $transitionId]]);
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300)
            return null;
        if (400 !== $status && 409 !== $status)
            throw new \RuntimeException($this->client->describeFailure($response, $path));

        $body = $response->getJSON() ?? [];
        $messages = [...($body['errorMessages'] ?? []), ...array_values($body['errors'] ?? [])];

        return [] === $messages ? $this->client->describeFailure($response, $path) : implode(' ', $messages);
    }

    /**
     * @param array<string,?array> $subtaskAssignees
     */
    private function toData(array $issue, array $subtaskAssignees = []): JiraIssueData
    {
        $fields = $issue['fields'] ?? [];
        $key = (string) $issue['key'];

        return new JiraIssueData(
            externalId: (string) $issue['id'],
            key: $key,
            summary: (string) ($fields['summary'] ?? ''),
            url: $this->client->site.'/browse/'.rawurlencode($key),
            project: (string) ($fields['project']['name'] ?? ''),
            issueType: (string) ($fields['issuetype']['name'] ?? ''),
            priority: $fields['priority']['name'] ?? null,
            statusId: (string) ($fields['status']['id'] ?? ''),
            status: (string) ($fields['status']['name'] ?? ''),
            statusCategory: $this->category($fields['status']['statusCategory']['key'] ?? null),
            sprint: isset($fields['sprint']['id']) ? new JiraSprint(
                id: (string) $fields['sprint']['id'],
                name: (string) ($fields['sprint']['name'] ?? ''),
                state: (string) ($fields['sprint']['state'] ?? ''),
            ) : null,
            assignee: isset($fields['assignee']['accountId']) ? $this->toUser($fields['assignee']) : null,
            // Jira gives a subtask without its project, sprint nor assignee : it is in the sprint of its parent, its assignee is read apart
            subtasks: array_map(
                fn (array $subtask) => $this->toData([...$subtask, 'fields' => [...$subtask['fields'] ?? [], 'project' => $fields['project'] ?? null, 'sprint' => $fields['sprint'] ?? null, 'assignee' => $subtaskAssignees[(string) $subtask['id']] ?? null]]),
                $fields['subtasks'] ?? [],
            ),
        );
    }

    private function toAttachment(array $attachment): JiraAttachment
    {
        return new JiraAttachment(
            id: (string) $attachment['id'],
            filename: (string) ($attachment['filename'] ?? ''),
            mimeType: (string) ($attachment['mimeType'] ?? 'application/octet-stream'),
            size: (int) ($attachment['size'] ?? 0),
            created: (string) ($attachment['created'] ?? ''),
            author: $attachment['author']['displayName'] ?? null,
        );
    }

    private function toUser(array $user): JiraUser
    {
        return new JiraUser(
            accountId: (string) $user['accountId'],
            name: (string) ($user['displayName'] ?? ''),
            avatar: $user['avatarUrls']['24x24'] ?? null,
        );
    }

    // Jira also has an `undefined` category, for statuses no one has classified
    private function category(?string $jiraCategory): string
    {
        return self::CATEGORIES[$jiraCategory] ?? JiraIssueData::CATEGORY_TODO;
    }
}
