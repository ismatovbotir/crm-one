<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ManageItemImages;
use App\Actions\Offers\SendOffer;
use App\Enums\SelectionChannel;
use App\Livewire\Catalog\ItemGallery;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Catalog\Exceptions\ItemImageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 01 section 15 / Spec 05 section 16: item pictures (validation, re-encoding without
 * metadata, permissions, gallery limits), the snapshot taken when an offer is sent, and pictures in
 * the printed offer (embedded, placeholder, setting and per-print toggle).
 */
class ItemImagesTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->manager()->create();
    }

    /** A real JPEG with an EXIF segment carrying a marker string. */
    private function jpegWithExif(int $width = 400, int $height = 300, string $marker = 'SECRET-GPS-MARKER'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        $payload = "Exif\0\0".$marker;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return UploadedFile::fake()->createWithContent('photo.jpg', substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    }

    private function upload(Item $item, ?UploadedFile $file = null, bool $asMain = false): ItemImage
    {
        return app(ManageItemImages::class)->add($item, $file ?? $this->jpegWithExif(), $this->manager, $asMain);
    }

    public function test_upload_is_re_encoded_without_metadata_with_thumbnails(): void
    {
        $item = $this->pricedItem('100.00');
        $image = $this->upload($item, $this->jpegWithExif(2400, 1200));

        $this->assertTrue($image->isMain());
        foreach (['path' => 1600, 'medium_path' => 320, 'thumb_path' => 96] as $column => $edge) {
            $bytes = Storage::disk('local')->get($image->{$column});
            $this->assertStringNotContainsString('SECRET-GPS-MARKER', $bytes, 'Metadata is dropped by re-encoding.');
            $this->assertStringNotContainsString('Exif', $bytes);
            [$width] = getimagesizefromstring($bytes);
            $this->assertSame($edge, $width);
            $this->assertMatchesRegularExpression('#^item-images/\d{4}/\d{2}/[A-Za-z0-9]{40}(-m|-t)?\.jpg$#', $image->{$column});
        }

        $this->actingAs($this->manager)->get($image->url('thumb'))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_bad_files_are_refused(): void
    {
        $item = $this->pricedItem('100.00');
        $svg = UploadedFile::fake()->createWithContent('logo.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $text = UploadedFile::fake()->createWithContent('a.png', 'not an image');

        foreach ([$svg, $text] as $file) {
            try {
                $this->upload($item, $file);
                $this->fail('Refused: '.$file->getClientOriginalName());
            } catch (ItemImageException $exception) {
                $this->assertSame('type', $exception->key);
            }
        }

        $this->expectExceptionObject(new ItemImageException('dimensions', ['max' => ManageItemImages::MAX_EDGE]));
        $this->upload($item, $this->jpegWithExif(6100, 10));
    }

    public function test_gallery_limits_permissions_and_main_picture(): void
    {
        $item = $this->pricedItem('100.00');
        $sales = User::factory()->sales()->create();

        Livewire::actingAs($sales)->test(ItemGallery::class, ['itemId' => $item->id])->assertDontSee(__('catalog.images.upload'))->set('upload', $this->jpegWithExif())->call('save')->assertForbidden();

        Livewire::actingAs($this->manager)->test(ItemGallery::class, ['itemId' => $item->id])->set('upload', $this->jpegWithExif())->call('save')->assertHasNoErrors();
        $main = $item->images()->sole();

        foreach (range(1, ManageItemImages::MAX_EXTRA) as $n) {
            $this->upload($item);
        }

        $this->assertSame(6, $item->images()->count());
        $this->expectPictureRefused(fn () => $this->upload($item), 'too_many');

        $extra = $item->images()->where('position', '>', 0)->first();
        app(ManageItemImages::class)->makeMain($extra, $this->manager);
        $this->assertTrue($extra->fresh()->isMain());
        $this->assertFalse($main->fresh()->isMain());

        app(ManageItemImages::class)->remove($extra->fresh(), $this->manager);
        $this->assertSame(1, $item->images()->where('position', 0)->count(), 'The next picture becomes main.');
        Storage::disk('local')->assertMissing($extra->medium_path);
    }

    public function test_sent_offer_keeps_its_picture_snapshot(): void
    {
        $rep = User::factory()->sales()->create();
        $item = $this->pricedItem('100.00');
        $first = $this->upload($item);
        $offer = $this->draftOffer($rep);
        $line = $this->addLine($offer, $item, '1');
        $this->assertSame($first->id, $line->item_image_id);
        $this->assertNull($line->image_path);

        app(SendOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email);
        $snapshot = $line->fresh()->image_path;
        $this->assertNotNull($snapshot);
        $this->assertStringStartsWith('offer-images/', $snapshot);

        // Replacing the item picture later never changes the sent offer.
        $this->upload($item, $this->jpegWithExif(500, 500, 'NEW'), true);
        Storage::disk('local')->assertMissing($first->medium_path);
        Storage::disk('local')->assertExists($snapshot);
        $this->assertSame($snapshot, $line->fresh()->image_path);

        $this->actingAs($rep)->get(route('media.offer-line-image', ['line' => $line->id]))->assertOk();
        $this->actingAs(User::factory()->sales()->create())->get(route('media.offer-line-image', ['line' => $line->id]))->assertNotFound();
    }

    public function test_offer_print_embeds_pictures_with_placeholder_and_toggles(): void
    {
        $rep = User::factory()->sales()->create();
        $withPicture = $this->pricedItem('100.00', ['name' => 'Pictured pump']);
        $this->upload($withPicture);
        $without = $this->pricedItem('50.00', ['name' => 'Plain valve']);
        $offer = $this->draftOffer($rep);
        $this->addLine($offer, $withPicture, '1');
        $this->addLine($offer, $without, '1');
        app(SendOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email);

        foreach (['uz', 'ru', 'en'] as $lang) {
            $this->actingAs($rep)->get(route('offers.print', ['offer' => $offer, 'lang' => $lang]))->assertOk()
                ->assertSee('src="data:image/jpeg;base64,', false)
                ->assertSee('data-print-image-placeholder', false)
                ->assertSee(__('documents.offer.no_image', [], $lang));
        }

        $this->actingAs($rep)->get(route('offers.print', ['offer' => $offer, 'images' => 0]))->assertOk()->assertDontSee('data-print-image', false);

        Setting::putMany(['documents.show_item_images' => '0']);
        $this->actingAs($rep)->get(route('offers.print', $offer))->assertOk()->assertDontSee('data-print-image', false);
        $this->actingAs($rep)->get(route('offers.print', ['offer' => $offer, 'images' => 1]))->assertOk()->assertSee('data-print-image', false);
    }

    private function expectPictureRefused(callable $action, string $key): void
    {
        try {
            $action();
            $this->fail('Expected refusal '.$key);
        } catch (ItemImageException $exception) {
            $this->assertSame($key, $exception->key);
        }
    }
}
