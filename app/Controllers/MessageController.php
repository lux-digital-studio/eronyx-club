<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ConversationRepository;
use App\Repositories\ListingRepository;
use App\Repositories\MessageRepository;
use App\Services\MessagingService;
use App\Validators\MessageValidator;
use RuntimeException;
use Throwable;

final class MessageController
{
    private Request $request;
    private Response $response;
    private Auth $auth;
    private Csrf $csrf;
    private MessagingService $messaging;
    private MessageValidator $validator;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $session = new Session();
        $this->auth = new Auth($session);
        $this->csrf = new Csrf($session);
        $pdo = (new Database())->connection();
        $this->messaging = new MessagingService(
            new ConversationRepository($pdo),
            new MessageRepository($pdo),
            new ListingRepository($pdo)
        );
        $this->validator = new MessageValidator();
    }

    public function index(): string
    {
        $userId = $this->userId();

        return $this->view('account/messages/index.php', [
            'conversations' => $this->messaging->inbox($userId),
            'unreadCount' => $this->messaging->unreadConversationCount($userId),
            'accountUrl' => $this->url('/account'),
            'threadBaseUrl' => $this->url('/account/messages'),
            'marketplaceUrl' => $this->url('/marketplace'),
        ]);
    }

    public function show(string $id): ?string
    {
        $conversationId = $this->routeId($id);

        if ($conversationId === null) {
            return $this->notFound();
        }

        return $this->renderThread($conversationId);
    }

    public function start(string $listingId): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $id = $this->routeId($listingId);

        if ($id === null) {
            return $this->notFound();
        }

        try {
            $conversationId = $this->messaging->startConversation($this->userId(), $id);
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/account/messages/' . $conversationId));

            return null;
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        } catch (Throwable) {
            $this->response->send('No se pudo iniciar la conversación.', 500);

            return null;
        }
    }

    public function send(string $id): ?string
    {
        if (!$this->csrf->validate($this->request->input('_csrf'))) {
            return $this->rejectCsrf();
        }

        $conversationId = $this->routeId($id);

        if ($conversationId === null) {
            return $this->notFound();
        }

        $validation = $this->validator->validate($this->request->all());

        if (!$validation['valid']) {
            return $this->renderThread($conversationId, $validation['errors'], $validation['data']['body']);
        }

        try {
            $this->messaging->sendMessage($this->userId(), $conversationId, $validation['data']['body']);
            $this->csrf->regenerate();
            $this->response->redirect($this->url('/account/messages/' . $conversationId));

            return null;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'recipient_inactive') {
                return $this->renderThread(
                    $conversationId,
                    ['body' => 'No se pueden enviar mensajes porque la otra cuenta ya no está activa.']
                );
            }

            return $this->mappedRuntimeResponse($exception);
        } catch (Throwable) {
            $this->response->send('No se pudo enviar el mensaje.', 500);

            return null;
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderThread(int $conversationId, array $errors = [], string $oldBody = ''): ?string
    {
        try {
            $thread = $this->messaging->openConversation($this->userId(), $conversationId);
        } catch (RuntimeException $exception) {
            return $this->mappedRuntimeResponse($exception);
        }

        return $this->view('account/messages/show.php', [
            'conversation' => $thread['conversation'],
            'messages' => $thread['messages'],
            'canSend' => $thread['can_send'],
            'errors' => $errors,
            'oldBody' => $oldBody,
            'csrf' => $this->csrf->token(),
            'currentUserId' => $this->userId(),
            'sendUrl' => $this->url('/account/messages/' . $conversationId),
            'inboxUrl' => $this->url('/account/messages'),
            'marketplaceUrl' => $this->url('/marketplace'),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function view(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require dirname(__DIR__) . '/Views/' . $view;

        return (string) ob_get_clean();
    }

    private function routeId(string $id): ?int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    private function userId(): int
    {
        return (int) $this->auth->id();
    }

    private function mappedRuntimeResponse(RuntimeException $exception): ?string
    {
        if ($exception->getMessage() === 'forbidden' || $exception->getMessage() === 'closed') {
            $this->response->forbidden();

            return null;
        }

        return $this->notFound();
    }

    private function rejectCsrf(): ?string
    {
        $this->response->send('Solicitud no válida.', 403);

        return null;
    }

    private function notFound(): ?string
    {
        $this->response->notFound();

        return null;
    }

    private function url(string $path): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return rtrim((string) $config['url'], '/') . '/' . ltrim($path, '/');
    }
}
