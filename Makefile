APP_DIR := laravel-app

env-prepare:
	cd $(APP_DIR) && cp -n .env.example .env

install:
	cd $(APP_DIR) && composer install

prepare:
	cd $(APP_DIR) && php artisan key:generate && php artisan migrate:fresh

up:
	cd $(APP_DIR) && docker compose up -d --build