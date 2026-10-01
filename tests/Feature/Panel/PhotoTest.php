<?php

namespace Tests\Feature\Panel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Фото участника панель забирает у Telegram по file_id и отдаёт оператору; токен бота наружу не выходит. */
class PhotoTest extends PanelTestCase
{
    /** GIF 1×1: Telegram отдаёт файлы как application/octet-stream, тип панель определяет по байтам. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function test_photo_is_fetched_from_telegram_and_served_without_token(): void
    {
        Http::fake([
            'api.telegram.org/file/*' => Http::response(base64_decode(self::GIF), 200, ['Content-Type' => 'application/octet-stream']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_id' => 'f1', 'file_path' => 'photos/file_1.jpg']]),
        ]);
        $message = $this->inbound($this->participant(), '', ['content_type' => 'photo', 'telegram_file_id' => 'f1', 'text' => null]);

        $response = $this->get("/messages/{$message->id}/photo")->assertOk();

        $response->assertHeader('Content-Type', 'image/gif');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(base64_decode(self::GIF), $response->getContent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bottest-token/getFile') && $r['file_id'] === 'f1');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telegram.org/file/bottest-token/photos/file_1.jpg');
    }

    public function test_message_without_photo_is_404(): void
    {
        $message = $this->inbound($this->participant(), 'текст');

        $this->get("/messages/{$message->id}/photo")->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $message = $this->inbound($this->participant(), '', ['content_type' => 'photo', 'telegram_file_id' => 'f1', 'text' => null]);
        auth()->logout();

        $this->get("/messages/{$message->id}/photo")->assertRedirect('/login');
    }

    public function test_non_image_file_is_not_served(): void
    {
        Http::fake([
            // Заголовку не верим: даже с image/jpeg HTML наружу не уйдёт.
            'api.telegram.org/file/*' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'image/jpeg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'documents/page.html']]),
        ]);
        $message = $this->inbound($this->participant(), '', ['content_type' => 'photo', 'telegram_file_id' => 'f1', 'text' => null]);

        $this->get("/messages/{$message->id}/photo")->assertStatus(415);
    }

    public function test_telegram_failure_is_502_without_token_in_body(): void
    {
        // Как в проде, без отладочной страницы: она показывает исходники из стека, в том числе этот тест.
        config(['app.debug' => false]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: file is too big'], 400)]);
        $message = $this->inbound($this->participant(), '', ['content_type' => 'photo', 'telegram_file_id' => 'f1', 'text' => null]);

        $response = $this->get("/messages/{$message->id}/photo")->assertStatus(502);

        $this->assertStringNotContainsString((string) config('promo.telegram.token'), (string) $response->getContent());
    }
}
