<?php

namespace App\Integrations\Jira;

class JiraProvider
{
    private const PAGE_LENGTH = 100;
    private const FIELDS = 'summary,status,issuetype,priority,project,sprint,assignee';
    private const CONTENT_FIELDS = self::FIELDS.',description,assignee,reporter,labels,comment,attachment';
    private const CONTENT_COMMENTS = 20;
    private const BOARD_JQL = 'statusCategory != Done{exclusion} ORDER BY updated DESC';

    private const CATEGORIES = [
        'new' => JiraIssueData::CATEGORY_TODO,
        'indeterminate' => JiraIssueData::CATEGORY_IN_PROGRESS,
        'done' => JiraIssueData::CATEGORY_DONE,
    ];

    public function __construct(
        private readonly JiraClient $client,
        private readonly int $board,
    ) {}

    /**
     * @param list<string> $excludedStatusIds
     *
     * @return list<JiraIssueData>
     */
    public function boardIssues(array $excludedStatusIds = []): array
    {
        $path = "/rest/agile/1.0/board/{$this->board}/issue";
        $issues = [];
        // Status ids come from Jira's board configuration, they are numbers : nothing else may enter the query
        $ids = array_filter($excludedStatusIds, fn (string $id) => ctype_digit($id));
        $exclusion = [] === $ids ? '' : ' AND status NOT IN ('.implode(', ', $ids).')';
        $parameters = ['jql' => str_replace('{exclusion}', $exclusion, self::BOARD_JQL), 'fields' => self::FIELDS, 'maxResults' => self::PAGE_LENGTH, 'startAt' => 0];

        while (true) {
            $page = $this->client->read($path, $parameters) ?? throw new \RuntimeException("Jira does not know the board {$this->board}");
            $values = $page['issues'] ?? [];
            array_push($issues, ...array_map(fn (array $issue) => $this->toData($issue), $values));

            $parameters['startAt'] += count($values);
            if ([] === $values || $parameters['startAt'] >= ($page['total'] ?? 0))
                return $issues;
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
    public function boardColumns(): array
    {
        $configuration = $this->client->read("/rest/agile/1.0/board/{$this->board}/configuration")
            ?? throw new \RuntimeException("Jira does not know the board {$this->board}");

        return array_map(fn (array $column) => new JiraColumn(
            name: (string) $column['name'],
            statusIds: array_map(fn (array $status) => (string) $status['id'], $column['statuses'] ?? []),
        ), $configuration['columnConfig']['columns'] ?? []);
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

    private function toData(array $issue): JiraIssueData
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
