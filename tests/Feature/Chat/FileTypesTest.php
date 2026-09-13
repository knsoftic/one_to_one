<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Rules\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * M16 — More file types (checked by content per extension) and HD photos.
 */
class FileTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('chat');

        $this->me = User::factory()->create();
        $this->conversation = Conversation::factory()->between($this->me, User::factory()->create())->create();
    }

    #[DataProvider('documents')]
    public function test_common_documents_archives_and_audio_files_can_be_sent(string $name, string $contentFactory): void
    {
        $this->send(['attachment' => $this->upload($name, self::$contentFactory())])
            ->assertCreated()
            ->assertJsonPath('type', 'document')
            ->assertJsonPath('attachment.name', $name);

        $message = Message::sole();
        $this->assertStringEndsWith('.'.pathinfo($name, PATHINFO_EXTENSION), $message->attachment);
    }

    public static function documents(): array
    {
        return [
            'Excel' => ['Budget 2026.xlsx', 'xlsx'],
            'PowerPoint' => ['Pitch.pptx', 'pptx'],
            'text' => ['notes.txt', 'text'],
            'CSV' => ['contacts.csv', 'csv'],
            'ZIP' => ['photos.zip', 'zip'],
            'RAR' => ['backup.rar', 'rar'],
            'MP3' => ['song.mp3', 'mp3'],
        ];
    }

    public function test_files_whose_content_does_not_match_their_extension_are_refused(): void
    {
        foreach ([
            ['page.txt', '<!doctype html><html><head><script>alert(1)</script></head><body>hi</body></html>'],
            ['archive.txt', self::zip()],
            ['installer.zip', "MZ\x90\x00\x03".str_repeat("\0", 128)],
            ['song.mp3', '<?php echo "hi";'],
            ['app.apk', self::zip()],
            ['script.js', 'alert(1)'],
        ] as [$name, $content]) {
            $this->send(['attachment' => $this->upload($name, $content)])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['attachment' => DocumentType::MESSAGE]);
        }

        $this->assertSame(0, Message::count());
        $this->assertSame([], Storage::disk('chat')->allFiles());
    }

    public function test_downloads_of_text_files_are_never_rendered_as_pages(): void
    {
        $url = $this->send(['attachment' => $this->upload('notes.txt', self::text())])->json('attachment.url');

        $response = $this->actingAs($this->me)->get($url)->assertOk();
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_photos_are_standard_size_unless_hd_is_chosen(): void
    {
        $this->send(['attachment' => UploadedFile::fake()->image('wide.jpg', 3600, 2400)])
            ->assertCreated()
            ->assertJsonPath('attachment.width', 1600)
            ->assertJsonPath('attachment.height', 1067)
            ->assertJsonPath('attachment.hd', false);

        $this->send(['attachment' => UploadedFile::fake()->image('wide-hd.jpg', 3600, 2400), 'quality' => 'hd'])
            ->assertCreated()
            ->assertJsonPath('attachment.width', 3072)
            ->assertJsonPath('attachment.height', 2048)
            ->assertJsonPath('attachment.hd', true);

        $this->send(['attachment' => UploadedFile::fake()->image('small.jpg', 800, 600), 'quality' => 'hd'])
            ->assertJsonPath('attachment.width', 800);

        $this->send(['attachment' => UploadedFile::fake()->image('x.jpg'), 'quality' => 'ultra'])->assertJsonValidationErrors('quality');
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)
            ->post("/conversations/{$this->conversation->id}/messages", $payload, ['Accept' => 'application/json']);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private static function office(string $contentType, string $part): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$part.'" ContentType="'.$contentType.'"/></Types>');
        $zip->addFromString($part, '<?xml version="1.0"?><root/>');
        $zip->close();

        return (string) file_get_contents($path);
    }

    private static function xlsx(): string
    {
        return self::office('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', 'xl/workbook.xml');
    }

    private static function pptx(): string
    {
        return self::office('application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml', 'ppt/presentation.xml');
    }

    private static function text(): string
    {
        return "Shopping list\n- milk\n- bread\n";
    }

    private static function csv(): string
    {
        return "name,phone\nAyesha,+923001234567\nBilal,+923331234567\n";
    }

    private static function zip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('readme.md', '# hello');
        $zip->close();

        return (string) file_get_contents($path);
    }

    private static function rar(): string
    {
        return "Rar!\x1A\x07\x01\x00".str_repeat("\0", 64);
    }

    private static function mp3(): string
    {
        // ID3v2 header followed by MPEG-1 Layer III frame headers.
        return 'ID3'."\x04\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x64".str_repeat("\0", 413), 4);
    }
}
