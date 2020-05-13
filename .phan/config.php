<?php

return [
  "directory_list" => [
    "src",
    "vendor",
  ],
  "exclude_analysis_directory_list" => [
    "vendor",
  ],
  'exclude_file_regex' => '@^vendor/.*/(tests?|Tests?)/@',
  "backward_compatibility_checks" => false,
];
