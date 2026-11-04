<?php

return [
    'max_active_fields_per_program' => 50,
    'max_options_per_field' => 100,
    'label_max_length' => 150,
    'text_answer_max_length' => 5000,
    'file_max_size_kb' => 20480,
    'file_default_mimes' => ['pdf', 'doc', 'docx', 'png', 'jpg'],
    'file_default_extensions' => ['pdf', 'doc', 'docx', 'png', 'jpg'],
    'allowed_field_types' => ['text', 'textarea', 'number', 'boolean', 'date', 'select', 'multi_select', 'file', 'url'],
    'allowed_scoring_match_types' => ['in_set', 'range', 'equals', 'provided'],
];
