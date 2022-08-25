<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class IntegrationService
{
    const REPORT_STR = '%s часа(ов) - %s %s, %s %s';
    const COMMENTS_LENGTH = 100;

    private JiraService $jiraService;
    private YouTrackService $youTrackService;
    private RedMineService $redMineService;

    public function __construct(
        RedMineService $redMineService,
        JiraService $jiraService,
        YouTrackService $youTrackService
    )
    {
        $this->redMineService = $redMineService;
        $this->jiraService =$jiraService;
        $this->youTrackService = $youTrackService;
    }

    public function getRedmineReport(): array
    {
        $issues = $this->redMineService
            ->setToken(Auth::user()->worker->redmine_api)
            ->getIssues();

        $reports = $this->redMineService->getReports($issues);

        return $this->filterReports($reports);
    }

    public function getJiraReport(): array
    {
        $usersIssues = $this->jiraService
            ->setToken(Auth::user()->worker->jira_api)
            ->setUserName(Auth::user()->worker->jira_username)
            ->getIssues();

        $reports = $this->jiraService->getReports($usersIssues);

        return $this->filterReports($reports);
    }

    public function getYouTrackReport(): array
    {
        $issues = $this->youTrackService
            ->setToken(Auth::user()->worker->you_track_api)
            ->getIssues();

        $reports = $this->youTrackService->getReports($issues);

        return $this->filterReports($reports);
    }

    private function filterReports(array $reports): array
    {
        $stringReports = implode(array_column($reports, 'report'));
        $minutes = array_sum(array_column($reports, 'minutes'));

        return [
            'reports' => $stringReports,
            'hours' => intdiv($minutes, 60),
            'minutes' => $minutes % 60
        ];
    }

    public static function getIssueLink($key, $url): string
    {
        return '<a href="' . $url . '">' . $key . '</a>';
    }
}