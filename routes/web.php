<?php

use App\Core\Route;
use App\Controllers\AppController;
use App\Controllers\AuthController;
use App\Controllers\StateController;
use App\Controllers\UserController;
use App\Controllers\ModuleController;
use App\Controllers\LessonController;
use App\Controllers\MediaController;

// Portal (página única)
Route::get('/', [AppController::class, 'index']);
Route::get('/api/version', [AppController::class, 'versionJson']);

// Estado inicial da tela (usuário logado, sistemas, módulos, aulas e — para a equipe — usuários)
Route::get('/api/state', [StateController::class, 'show']);

// Autenticação e cadastro
Route::post('/api/login', [AuthController::class, 'login']);
Route::post('/api/logout', [AuthController::class, 'logout']);
Route::post('/api/register', [AuthController::class, 'register']);
Route::post('/api/password', [AuthController::class, 'changePassword']);

// Usuários: aprovação e permissões
Route::post('/api/users/{id}/status', [UserController::class, 'status']);
Route::post('/api/users/{id}/access', [UserController::class, 'access']);
Route::post('/api/users/{id}/reset-password', [UserController::class, 'resetPassword']);
Route::post('/api/users/{id}/profile', [UserController::class, 'profile']);

// Notificações: marcar como vistas
Route::post('/api/notifications/seen', [App\Controllers\ProgressController::class, 'notifSeen']);

// Pontuação e relatórios
Route::post('/api/lessons/{id}/progress', [App\Controllers\ProgressController::class, 'track']);
Route::get('/api/reports/ranking', [App\Controllers\ReportController::class, 'ranking']);
Route::get('/api/reports/progress', [App\Controllers\ReportController::class, 'progress']);

// Provas por módulo
Route::get('/api/quizzes/{id}', [App\Controllers\QuizController::class, 'show']);
Route::get('/api/quizzes/{id}/edit', [App\Controllers\QuizController::class, 'edit']);
Route::post('/api/quizzes/{id}', [App\Controllers\QuizController::class, 'save']);
Route::post('/api/quizzes/{id}/submit', [App\Controllers\QuizController::class, 'submit']);
Route::post('/api/quizzes/{id}/delete', [App\Controllers\QuizController::class, 'destroy']);

// Sugestões de melhoria
Route::post('/api/suggestions', [App\Controllers\SuggestionController::class, 'store']);
Route::post('/api/suggestions/{id}/status', [App\Controllers\SuggestionController::class, 'status']);
Route::post('/api/suggestions/{id}/delete', [App\Controllers\SuggestionController::class, 'destroy']);

// Empresa pelo CNPJ (tela de cadastro)
Route::get('/api/companies/{cnpj}', [App\Controllers\CompanyController::class, 'lookup']);

// Módulos
Route::post('/api/modules', [ModuleController::class, 'store']);
Route::post('/api/modules/{id}', [ModuleController::class, 'update']);
Route::post('/api/modules/{id}/move', [ModuleController::class, 'move']);
Route::post('/api/modules/{id}/delete', [ModuleController::class, 'destroy']);

// Aulas / vídeos (YouTube ou arquivo enviado)
Route::post('/api/lessons', [LessonController::class, 'save']);
Route::post('/api/lessons/{id}/remove-video', [LessonController::class, 'removeVideo']);
Route::post('/api/lessons/{id}/delete', [LessonController::class, 'destroy']);
Route::post('/api/lessons/{id}/move', [LessonController::class, 'move']);

// Arquivos de vídeo e miniaturas (com checagem de permissão)
Route::get('/media/video/{id}', [MediaController::class, 'video']);
Route::get('/media/thumb/{id}', [MediaController::class, 'thumb']);
