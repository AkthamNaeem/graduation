<?php

return [
    'retrieved' => 'Activity retrieved successfully.',
    'role_not_allowed' => 'The activity page is available to job seekers only.',
    'types' => [
        'test' => 'Test',
        'interview' => 'Interview',
        'information_request' => 'Information request',
        'application_status' => 'Application status update',
        'application_reminder' => 'Application reminder',
        'final_decision' => 'Final decision',
    ],
    'actions' => [
        'start_test' => 'Start test',
        'continue_test' => 'Continue test',
        'submit_information' => 'Submit information',
        'confirm_interview' => 'Confirm interview',
        'view_interview' => 'View interview',
        'view_application' => 'View application',
        'view_test_result' => 'View test result',
        'none' => 'No action',
    ],
    'items' => [
        'test_pending_title' => 'Test awaiting completion',
        'test_continue_title' => 'Test in progress',
        'test_description' => 'Complete the test before its deadline.',
        'interview_title' => 'Upcoming interview',
        'information_request_title' => 'Additional information requested',
    ],
    'validation' => [
        'group' => 'The selected activity group is invalid.',
        'type' => 'The selected activity type is invalid.',
        'sort_by' => 'The selected activity sort is invalid.',
        'sort_direction' => 'The selected sort direction is invalid.',
        'timezone' => 'The timezone must be a valid IANA timezone.',
        'date_range' => 'The end date must be on or after the start date.',
    ],
];
