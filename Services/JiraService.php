<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Validation\ValidationException;


class JiraService
{
    const USER_URL = '/rest/api/2/user';
    const ISSUES_URL = '/rest/api/2/search';
    const WORKLOGS_URL = '/rest/api/2/issue/%s/worklog';
    private string $apiToken;
    private string $userName;

    public function setToken($apiToken): self
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function setUserName($userName): self
    {
        $this->userName = $userName;

        return $this;
    }

    /**
     * @throws ValidationException
     */
    private function checkUser(): void
    {
        $user = $this->getQueryData(self::USER_URL . "?username=$this->userName");
        if (isset($user['errors'])) {
            throw ValidationException::withMessages(['jira_api' => trans('validation.correct_jira_username')]);
        }
    }

    public function getIssues(): array
    {
        $this->checkUser();

        $allIssues = $this->getQueryData(
            self::ISSUES_URL . '?jql=Updated>=startOfDay()'
        );

        $this->setWorkLogs($allIssues['issues']);
        return $allIssues['issues'];
    }

    private function setWorkLogs(array &$issues): void
    {
        //заносим активность(worklogs) по каждому пользователю
        foreach ($issues as &$issue) {
            $issue['fields']['worklogs'] = $this->getUsersWorkLogs(
                $this->getQueryData(sprintf(self::WORKLOGS_URL, $issue['id']))['worklogs']
            );
        }
    }

    private function getUsersWorkLogs(array $workLogs): array
    {
        return array_filter($workLogs, function ($workLog) {
            return $this->isTodayUserWorkLog($workLog);
        });
    }

    private function getReport(array $issue): array
    {
        $minutes = 0;
        $comments = "";

        foreach ($issue['fields']['worklogs'] as $workLog) {//высчитываем итоговое время и все комментарии за весь день
            $minutes += $workLog['timeSpentSeconds'] / 60;
            $comments .= $workLog['comment'] . '; ';
        }

        return [
            'report' => $this->getReportString($comments, $minutes, $issue),
            'minutes' => $minutes
        ];
    }

    private function getReportString(string $comments, $minutes, array $issue): string
    {
        if ($minutes == 0) {
            return '';
        }
        $comments = trim(preg_replace('/\s\s+/', ' ', $comments));

        return sprintf(
            IntegrationService::REPORT_STR,
            (string)$minutes / 60,
            IntegrationService::getIssueLink($issue['key'], \Config::get("jira.url") . '/browse/' . $issue['key']),
            $issue['fields']['summary'],
            $issue['fields']['status']['name'],
            $comments ? ', Комментарий: ' . $comments : ''
        );
    }

    public function getReports(array $issues): array
    {
        return array_map([$this, 'getReport'], $issues);
    }

    private function getQueryData(string $url, string $method = 'GET'): array
    {
        $client = new Client([
            'base_uri' => \Config::get("jira.url"),
            'http_errors' => false,
            'verify' => false,
            'allow_redirects' => true
        ]);
        $options = [
            'headers' =>//заголовки согласно документации
                [
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Cache-Control' => 'no-cache',
                    'Content-Type' => 'application/json'
                ],
            []
        ];

        try {
            $data = $client->request(
                $method,
                $url,
                $options
            );
        } catch (GuzzleException $e) {
            throw ValidationException::withMessages(['jira_api' => trans('validation.get_error')]);
        }

        if ($data->getStatusCode() == 401) { //ошибка авторизации
            throw ValidationException::withMessages(['jira_api' => trans('validation.correct_jira_api')]);
        }

        return json_decode($data->getBody()->getContents(), true);
    }

    private function isTodayUserWorkLog(array $workLog): bool
    {
        return $workLog['author']['name'] == $this->userName && date('Y-m-d') == date('Y-m-d',
                strtotime($workLog['updated']));
    }
}