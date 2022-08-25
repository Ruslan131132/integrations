<?php

namespace App\Services;

use Redmine\Client\NativeCurlClient;
use Illuminate\Validation\ValidationException;
use Redmine\Exception\ClientException;

class RedMineService
{
    private ?string $apiToken;
    private ProfileService $profileService;
    private NativeCurlClient $client;
    private $currentUser;
    private $spentOnTime;

    public function __construct(ProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    public function setToken($apiToken): self
    {
        $this->apiToken = $apiToken;
        $this->setClient();
        $this->setUser();
        $this->setSpentOnTime();

        return $this;
    }

    private function setClient()
    {
        $this->client = new NativeCurlClient(\Config::get("redmine.url"), $this->apiToken);
    }

    private function setUser()
    {
        try {
            $this->currentUser = $this->client->getApi('user')->getCurrentUser();
        } catch (ClientException $e) {
            throw ValidationException::withMessages(['redmine_api' => trans('validation.get_error')]);
        }

        if (!$this->currentUser) {
            throw ValidationException::withMessages(['redmine_api' => trans('validation.correct_redmine_api')]);
        }
    }

    private function setSpentOnTime()
    {
        $this->spentOnTime = $this->profileService->getDateFrom()->format('Y-m-d');
    }

    public function getIssues(): array
    {
        $issues = $this->client->getApi('time_entry')->all([
            'spent_on' => $this->spentOnTime,
            'user_id' => $this->currentUser['user']['id']
        ]);

        if (!$issues['time_entries']) {
            return [];
        }

        return $this->filterIssues($issues['time_entries']);
    }

    private function filterIssues(array $issues): array
    {
        $filteredIssues = array_map([$this, 'filterIssue'], $issues);

        return $this->sortIssuesById($filteredIssues);
    }

    private function filterIssue(array $issue): array
    {
        $redmineIssue = $this->client->getApi('issue')->show($issue['issue']['id'], ['include' => 'journals']);
        //фильтруем комментарии, получаем только для конкретного юзера и за определенную дату
        $dailyReportComments = $this->filterCommentsByDateAndUserId(
            $redmineIssue['issue']['journals'],
            $this->spentOnTime,
            $this->currentUser
        );

        return [
            'id' => $issue['issue']['id'],
            'hours' => $issue['hours'],
            'minutes' => round($issue['hours'] * 60),
            'subject' => $redmineIssue['issue']['subject'],
            'status' => $redmineIssue['issue']['status']['name'],
            'comments' => $dailyReportComments
        ];
    }

    private function sortIssuesById(array $filteredIssues): array
    {
        $result = [];
        foreach ($filteredIssues as $issue) {
            if (!array_key_exists($issue['id'], $result)) {
                $result[$issue['id']] = $issue;
            } else {
                $result[$issue['id']]['hours'] += $issue['hours'];
                $result[$issue['id']]['minutes'] += $issue['minutes'];
            }
        }

        return $result;
    }

    private function filterCommentsByDateAndUserId(array $comments, $date, $userId): string
    {
        $dailyReportComments = array_filter($comments,
            function ($comment) use ($date, $userId) {
                return ($comment['user']['id'] === $userId) &&
                    (strpos($comment['created_on'], $date) !== false);
            });

        return strip_tags(mb_substr(implode(', ', array_map(function ($comment) {
            return $comment['notes'];
        }, $dailyReportComments)), 0, IntegrationService::COMMENTS_LENGTH));
    }

    private function getReport(array $issue): array
    {
        return [
            'report' => $this->getReportString($issue['comments'], $issue),
            'minutes' => $issue['minutes']
        ];
    }

    public function getReports(array $issues): array
    {
        return array_map([$this, 'getReport'], $issues);
    }

    private function getReportString(string $comments, array $issue): string
    {
        $comments = trim(preg_replace('/\s\s+/', ' ', $comments));

        return sprintf(IntegrationService::REPORT_STR,
            $issue['hours'],
            IntegrationService::getIssueLink($issue['id'], \Config::get("redmine.url") . '/issues/' . $issue['id']),
            $issue['subject'],
            $issue['status'],
            $comments ? ', Комментарий: ' . $issue['comments'] : ''
        );
    }
}