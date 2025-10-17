<?php
require_once 'encryption.php';

$redirects = [
    'dashboard_api'              => enc_page('admin_dashboard', '../'),
    'dashboard'                  => enc_page('admin_dashboard'),
    'officials'                  => enc_page('official_info'),
    'officials_api'              => enc_page('official_info', '../'),
    'residents'                  => enc_page('resident_info'),
    'residents_api'              => enc_page('resident_info', '../'),
    'barangay_info_page'         => enc_page('barangay_information'),
    'appointments'               => enc_page('view_appointments'),
    'unlink'                     => enc_page('unlink_relationship'),
    'audit'                      => enc_page('audit'),
    'residents_audit'            => enc_page('residents_audit'),
    'beso'                       => enc_page('beso'),
    'announcements'              => enc_page('announcements'),

    // Forms
    'cert_senior'                => enc_page('senior_certification'),
    'cert_postal'                => enc_page('postalId_certification'),
    'cert_barangay'              => enc_page('barangay_certification'),
    'cert_clearance'             => enc_page('barangay_clearance'),
    'families'                   => enc_page('Pages/linked_families'),
    'family'                     => enc_page('linked_families'),

    // Events
    'event_list'                 => enc_page('event_list'),
    // 'event_list_api'           => enc_page('event_list', '../'),
    'event_calendar'             => enc_page('event_calendar'),
    'guidelines'                 => enc_page('add_guidelines'),

    // Others
    'feedbacks'                  => enc_page('feedbacks'),
    'faq'                        => enc_page('faq'),
    'case_list'                  => enc_page('case_list'),
    'case_add'                   => enc_page('case_add'),
    'role_add'                   => enc_page('Role_add'),
    'zone_add'                   => enc_page('Zone_add'),
    'zone_leaders'               => enc_page('Zone_leaders'),
    'archive'                    => enc_page('archive'),
    'barangay_officials'         => enc_page('barangay_official_list'),
    'barangay_info_admin'        => enc_page('barangay_info'),
    'urgent_request'             => enc_page('urgent_request'),
    'reports'                    => enc_page('reports'),
    'certificates_list'          => enc_page('certificate_list'),
    'time_slots'                 => enc_page('time_slot'),

    // API / class handlers
    'update_employee'            => '../class/update_employee.php', // for pages in subfolders
    'update_employee_root'       => 'class/update_employee.php',    // for root-level pages
];
