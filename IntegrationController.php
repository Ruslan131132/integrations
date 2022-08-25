<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\IntegrationService;


class IntegrationController extends Controller
{

    private $integrationService;

    public function __construct(IntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    public function getTokens(): array
    {
        $user = Auth::user()->worker;

        return [
            'jira_api' => $user->jira_api,
            'you_track_api' => $user->you_track_api,
            'redmine_api' => $user->redmine_api,
            'jira_username' => $user->jira_username,
        ];
    }

    public function updateTokens(Request $request)
    {
        $worker = Auth::user()->worker;
        $worker->fill($request->all());
        $worker->save();

        return $worker->toArray();
    }

    public function getRedmineReport(): array
    {
        return $this->integrationService->getRedmineReport();
    }

    public function getJiraReport(): array
    {
        return $this->integrationService->getJiraReport();
    }

    public function getYouTrackReport(): array
    {
        return $this->integrationService->getYouTrackReport();
    }
}
