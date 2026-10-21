<?php

return [

    'title' => 'Departments',
    'intro' => 'Departments and their members. Module rights follow the org chart: a department role (head, specialist, viewer) adds rights on top of the global role.',
    'new' => 'New department',
    'create' => 'Create department',
    'back' => 'Back to departments',
    'details' => 'Department details',
    'search_placeholder' => 'Search name or code',
    'empty' => 'No departments match your filters.',

    'code' => 'Code',
    'code_hint' => 'Lowercase letters, digits, "-" and "_". Used by the permission map.',
    'name_in' => 'Name (:language)',
    'names_hint' => 'Standard departments have built-in names in every language; fill these fields only to override them.',
    'parent' => 'Parent department',
    'no_parent' => 'None (top level)',
    'sort_order' => 'Sort order',
    'is_active' => 'Active (inactive departments grant no rights)',
    'head' => 'Head',
    'members_count' => 'Members',

    'members' => 'Members',
    'members_hint' => 'Exactly one head per department; each user has one primary department. Changes take effect on the user\'s next request.',
    'no_members' => 'This department has no members yet.',
    'member' => 'User',
    'member_role' => 'Department role',
    'primary' => 'Primary',
    'make_primary' => 'Make primary',
    'choose_user' => 'Choose a user...',
    'add_member' => 'Add member',
    'remove' => 'Remove',
    'remove_confirm' => 'Remove :name from this department?',

    'created' => 'Department :name created.',
    'updated' => 'Department :name updated.',
    'member_added' => ':name added to the department.',
    'member_updated' => 'Membership of :name updated.',
    'member_removed' => ':name removed from the department.',

    'errors' => [
        'head_taken' => 'This department already has a head (:name). Change their role first.',
        'portal_user' => 'Portal users cannot be members of internal departments.',
    ],

    'roles' => [
        'head' => 'Head',
        'specialist' => 'Specialist',
        'viewer' => 'Viewer',
    ],

    'scopes' => [
        'own' => 'Own records',
        'linked' => 'Linked records',
        'department' => 'Department records',
        'all' => 'All records',
    ],

    'names' => [
        'sales' => 'Sales',
        'purchase' => 'Purchase',
        'logistics' => 'Import & Transportation',
        'finance' => 'Accounting & Finance',
        'management' => 'Management',
        'it' => 'Admin & IT',
        'support' => 'Support',
        'warehouse' => 'Warehouse',
        'service' => 'Service',
    ],

];
