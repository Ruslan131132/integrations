<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Validation\ValidationException;

class YouTrackService
{
    //поля для парметра fields являются опциональными и выбраны согласно предпочтениям к получаемым данным
    const USER_URL = '/api/users/me?fields=id,login,name';
    const WORKLOGS_URL = '/api/workItems' .
    '?fields=issue(idReadable,summary,customFields(name,value(localizedName))),text,duration(minutes,presentation),date';
    private ?string $apiToken;

    public function setToken($apiToken): self
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function getIssues(): array
    {
        $user = $this->getUser();
        $time = (new \DateTime())->setTime(0, 0)->getTimestamp();
        $workLogs = $this->getQueryData(
            self::WORKLOGS_URL .
            "&author={$user['id']}" .
            "&createdStart={$time}000"
        );

        return $this->sortWorkLogsByIssues($workLogs);
    }

    private function sortWorkLogsByIssues(array $workLogs): array
    {
        $issues = [];
        foreach ($workLogs as $workLog) {
            $issues[$workLog['issue']['idReadable']]['workLogs'][] =
                [
                    'comment' => $workLog['text'],
                    'hours' => $workLog['duration']['minutes'] / 60,
                    'minutes' => $workLog['duration']['minutes']
                ];
            $issues[$workLog['issue']['idReadable']]['name'] = $workLog['issue']['idReadable'];
            $issues[$workLog['issue']['idReadable']]['summary'] = $workLog['issue']['summary'];
            $issues[$workLog['issue']['idReadable']]['status'] = $workLog['issue']['customFields'][2]['value']['localizedName'];
        }

        return $issues;
    }

    private function getUser(): array
    {
        return $this->getQueryData(self::USER_URL);
    }

    /**
     * @throws ValidationException
     * @throws GuzzleException
     */
    private function getQueryData(string $url, string $method = 'GET'): array
    {
        $client = new Client([
            'base_uri' => \Config::get("youtrack.url"),
            'http_errors' => false
        ]);
        $options = [
            'headers' =>//заголовки согласно документации
                [
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer perm:{$this->apiToken}",
                    'Cache-Control' => 'no-cache',
                    'Content-Type' => 'application/json'
                ]
        ];

        try {
            $data = $client->request(
                $method,
                $url,
                $options
            );
        } catch (GuzzleException $e) {
            throw ValidationException::withMessages(['you_track_api' => trans('validation.get_error')]);
        }

        if ($data->getStatusCode() == 401) { //ошибка авторизации
            throw ValidationException::withMessages(['you_track_api' => trans('validation.correct_you_track_api')]);
        }

        return json_decode($data->getBody()->getContents(), true);
    }

    private function getReport(array $issue): array
    {
        $hours = 0;
        $minutes = 0;
        $comments = "";

        foreach ($issue['workLogs'] as $workLog) {//высчитываем итоговое время и все комментарии за весь день
            $minutes += $workLog['minutes'];
            $hours += $workLog['hours'];
            $comments .= $workLog['comment'] . '; ';
        }

        return [
            'report' => $this->getReportString($comments, $minutes, $issue),
            'minutes' => $minutes
        ];
    }

    public function getReports(array $issues): array
    {
        return array_map([$this, 'getReport'], $issues);
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
            IntegrationService::getIssueLink($issue['name'], \Config::get("youtrack.url") . '/issue/' . $issue['name']),
            $issue['summary'],
            $issue['status'],
            $comments ? ', Комментарий: ' . $comments : ''
        );
    }
}