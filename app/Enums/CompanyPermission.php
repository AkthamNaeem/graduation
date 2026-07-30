<?php

namespace App\Enums;

enum CompanyPermission: string
{
    case UPDATE_COMPANY = 'update_company';
    case VIEW_TEAM = 'view_team';
    case MANAGE_TEAM = 'manage_team';
    case TRANSFER_OWNERSHIP = 'transfer_ownership';
    case VIEW_JOBS = 'view_jobs';
    case MANAGE_JOBS = 'manage_jobs';
    case VIEW_APPLICATIONS = 'view_applications';
    case MANAGE_APPLICATIONS = 'manage_applications';
    case VIEW_TESTS = 'view_tests';
    case MANAGE_TESTS = 'manage_tests';
    case GRADE_TESTS = 'grade_tests';
    case VIEW_INTERVIEWS = 'view_interviews';
    case MANAGE_INTERVIEWS = 'manage_interviews';
    case EVALUATE_INTERVIEWS = 'evaluate_interviews';
    case MANAGE_INTERNAL_NOTES = 'manage_internal_notes';
}
