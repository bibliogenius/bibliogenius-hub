<?php require dirname(__DIR__)."/vendor/autoload.php"; (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__)."/.env"); print_r($_ENV["JIRA_BASE_URL"]);
