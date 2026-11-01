<?php

namespace Base\Estate\Service;

use Base\Estate\Entity\StoryEntry;
use Base\Estate\Entity\StoryPhoto;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ready-to-post images of a story's entry, made on the server with Imagine
 * (the core's own image library; Imagick when the extension is there, GD
 * otherwise): for each before/after pair the team cleared, the two photos
 * side by side (one above the other in portrait) and a two-slide carousel
 * (before, then after), in the three formats the networks take - square
 * 1080×1080, portrait 1080×1350, landscape 1200×630 (a link's preview) -,
 * each with a band carrying a short caption (the title, the place) and the
 * company's mark. Cached on disk by what they are made of; a zip of them
 * all with the suggested text.
 */
class StoryImages
{
    /** name => [width, height, band height] */
    public const FORMATS = [
        'square' => [1080, 1080, 150],
        'portrait' => [1080, 1350, 170],
        'landscape' => [1200, 630, 112],
    ];

    /** pair: the two photos together; before, after: the carousel's two slides. */
    public const LAYOUTS = ['pair', 'before', 'after'];

    private const FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
    ];

    private ?ImagineInterface $imagine = null;

    public function __construct(
        private readonly Stories $stories,
        #[Autowire('%kernel.cache_dir%/estate_story')] private readonly string $cacheDir,
        #[Autowire('%estate.story.font%')] private readonly ?string $font = null,
        #[Autowire('%estate.story.font_regular%')] private readonly ?string $fontRegular = null,
        #[Autowire('%estate.story.mark%')] private readonly ?string $mark = null,
        #[Autowire('%estate.story.color%')] private readonly string $color = '#1f2328',
    ) {
    }

    /**
     * Every image of an entry, by file name: <view>-<layout>-<format>.jpg, the cleared pairs only.
     *
     * @return array<string, array{pair: int, layout: string, format: string}>
     */
    public function images(StoryEntry $entry): array
    {
        $images = [];
        foreach ($entry->getPairs(true) as $index => $pair) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $pair['view'] ?: 'vue'))), '-') ?: 'vue';
            foreach (self::LAYOUTS as $layout) {
                foreach (array_keys(self::FORMATS) as $format) {
                    $images[sprintf('%02d-%s-%s-%s.jpg', $index + 1, $slug, 'pair' === $layout ? 'avant-apres' : ('before' === $layout ? '1-avant' : '2-apres'), $format)] = ['pair' => $index, 'layout' => $layout, 'format' => $format];
                }
            }
        }

        return $images;
    }

    /**
     * One image's file (a JPEG), made if it is not in the cache yet.
     *
     * @throws \InvalidArgumentException for a pair, layout or format that is not there
     */
    public function render(StoryEntry $entry, int $pairIndex, string $layout, string $format): string
    {
        $pairs = $entry->getPairs(true);
        if (!isset($pairs[$pairIndex], self::FORMATS[$format]) || !\in_array($layout, self::LAYOUTS, true)) {
            throw new \InvalidArgumentException('No such image.');
        }
        $pair = $pairs[$pairIndex];
        $title = $entry->getTitle();
        $place = $this->stories->place($entry);
        $company = $entry->getOrganisation()->getName();
        $key = sha1(implode('|', [$entry->getId(), $pair['before']->getImage(), $pair['after']->getImage(), $layout, $format, $title, $place, $company, $this->color, $this->mark, $this->fontPath(), $this->fontRegularPath(), 4]));
        $target = $this->cacheDir.'/'.$key.'.jpg';
        if (is_file($target)) {
            return $target;
        }
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException('Cannot write '.$this->cacheDir);
        }

        $this->compose($this->open($pair['before']), $this->open($pair['after']), $layout, $format, $title, implode(' · ', array_filter([$place, $company])))
            ->save($target, ['jpeg_quality' => 88]);

        return $target;
    }

    /**
     * One image from its two photos: the layout (pair, before, after) in the format, the band
     * with the title and the line under it.
     */
    public function compose(ImageInterface $before, ImageInterface $after, string $layout, string $format, string $title, string $sub): ImageInterface
    {
        if (!isset(self::FORMATS[$format]) || !\in_array($layout, self::LAYOUTS, true)) {
            throw new \InvalidArgumentException('No such image.');
        }
        [$width, $height, $band] = self::FORMATS[$format];
        $imagine = $this->imagine();
        $palette = new RGB();
        $canvas = $imagine->create(new Box($width, $height), $palette->color('#ffffff'));
        $area = $height - $band;

        if ('pair' === $layout) {
            $gap = 6;
            if ('portrait' === $format) {
                // One above the other: two wide photos read better than two narrow strips.
                $half = intdiv($area - $gap, 2);
                $canvas->paste($this->cover($before, $width, $half), new Point(0, 0));
                $canvas->paste($this->cover($after, $width, $area - $half - $gap), new Point(0, $half + $gap));
                $this->chip($canvas, 'AVANT', 28, 28, $width);
                $this->chip($canvas, 'APRÈS', 28, $half + $gap + 28, $width);
            } else {
                $half = intdiv($width - $gap, 2);
                $canvas->paste($this->cover($before, $half, $area), new Point(0, 0));
                $canvas->paste($this->cover($after, $width - $half - $gap, $area), new Point($half + $gap, 0));
                $this->chip($canvas, 'AVANT', 24, 24, $width);
                $this->chip($canvas, 'APRÈS', $half + $gap + 24, 24, $width);
            }
        } else {
            $canvas->paste($this->cover('before' === $layout ? $before : $after, $width, $area), new Point(0, 0));
            $this->chip($canvas, 'before' === $layout ? 'AVANT  1/2' : 'APRÈS  2/2', 28, 28, $width);
        }

        $this->band($canvas, $width, $height, $band, $title, $sub);

        return $canvas;
    }

    /**
     * A zip of every image of an entry and the suggested text (texte.txt), its path. Null when no
     * pair is cleared: nothing to post.
     */
    public function zip(StoryEntry $entry): ?string
    {
        $images = $this->images($entry);
        if (!$images) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'story').'.zip';
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Cannot write the zip.');
        }
        foreach ($images as $name => $image) {
            $zip->addFile($this->render($entry, $image['pair'], $image['layout'], $image['format']), $name);
        }
        $zip->addFromString('texte.txt', $this->stories->caption($entry, 'instagram')."\n");
        $zip->close();

        return $path;
    }

    public function imagine(): ImagineInterface
    {
        return $this->imagine ??= class_exists(\Imagick::class) ? new \Imagine\Imagick\Imagine() : new \Imagine\Gd\Imagine();
    }

    private function open(StoryPhoto $photo): ImageInterface
    {
        $file = $photo->getImageFile();
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
            throw new \InvalidArgumentException(sprintf('The photo « %s » has no file.', $photo->getLabel()));
        }
        $image = $this->imagine()->open($file->getPathname());
        if (class_exists(\Imagine\Filter\Basic\Autorotate::class)) {
            $image = (new \Imagine\Filter\Basic\Autorotate())->apply($image);
        }

        return $image;
    }

    /** The photo filling the box, cropped about its middle. */
    private function cover(ImageInterface $image, int $width, int $height): ImageInterface
    {
        $size = $image->getSize();
        $ratio = max($width / $size->getWidth(), $height / $size->getHeight());
        $scaled = $image->copy()->resize(new Box(max($width, (int) ceil($size->getWidth() * $ratio)), max($height, (int) ceil($size->getHeight() * $ratio))));
        $s = $scaled->getSize();

        return $scaled->crop(new Point(intdiv($s->getWidth() - $width, 2), intdiv($s->getHeight() - $height, 2)), new Box($width, $height));
    }

    /** A label on the photo, white on the band's colour. */
    private function chip(ImageInterface $canvas, string $text, int $x, int $y, int $width): void
    {
        $palette = new RGB();
        $size = $width >= 1200 ? 22 : 24;
        $font = $this->font($this->fontPath(), $size, '#ffffff');
        if (null === $font) {
            return;
        }
        $box = $font->box($text);
        $padX = 16;
        $padY = 10;
        $canvas->draw()->rectangle(new Point($x, $y), new Point($x + $box->getWidth() + 2 * $padX, $y + $box->getHeight() + 2 * $padY), $palette->color($this->color), true);
        $canvas->draw()->text($text, $font, new Point($x + $padX, $y + $padY));
    }

    /** The band under the photos: the title and the place on the left, the company's mark on the right. */
    private function band(ImageInterface $canvas, int $width, int $height, int $band, string $title, string $sub): void
    {
        $palette = new RGB();
        $top = $height - $band;
        $canvas->draw()->rectangle(new Point(0, $top), new Point($width - 1, $height - 1), $palette->color($this->color), true);
        $margin = $width >= 1200 ? 36 : 44;
        $right = $width - $margin;

        $mark = $this->markImage($band - 48);
        if ($mark) {
            $right -= $mark->getSize()->getWidth() + 24;
            $canvas->paste($mark, new Point($width - $margin - $mark->getSize()->getWidth(), $top + intdiv($band - $mark->getSize()->getHeight(), 2)));
        }

        $titleSize = (int) round($band * 0.27);
        $subSize = (int) round($band * 0.17);
        $titleFont = $this->font($this->fontPath(), $titleSize, '#ffffff');
        $subFont = $this->font($this->fontRegularPath() ?? $this->fontPath(), $subSize, '#ffffffcc');
        if (null === $titleFont || null === $subFont) {
            return;
        }
        $available = $right - $margin;
        // A long title shrinks down to two thirds of its size before it is cut.
        for ($size = $titleSize; $size > $titleSize * 2 / 3 && $titleFont->box($title)->getWidth() > $available; $size -= 2) {
            $titleFont = $this->font($this->fontPath(), $size, '#ffffff');
        }
        $title = $this->fit($title, $titleFont, $available);
        $sub = $this->fit($sub, $subFont, $available);
        $titleHeight = $titleFont->box('Ag')->getHeight();
        $subHeight = $subFont->box('Ag')->getHeight();
        $y = $top + intdiv($band - $titleHeight - $subHeight - 10, 2);
        $canvas->draw()->text($title, $titleFont, new Point($margin, $y));
        $canvas->draw()->text($sub, $subFont, new Point($margin, $y + $titleHeight + 10));
    }

    /** The text cut with an ellipsis to fit the width. */
    private function fit(string $text, \Imagine\Image\FontInterface $font, int $width): string
    {
        if ($font->box($text)->getWidth() <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $font->box($text.'…')->getWidth() > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function markImage(int $height): ?ImageInterface
    {
        if (!$this->mark || !is_file($this->mark) || $height < 8) {
            return null;
        }
        try {
            $mark = $this->imagine()->open($this->mark);
        } catch (\Throwable) {
            return null;
        }
        $size = $mark->getSize();

        return $mark->resize(new Box(max(1, (int) round($size->getWidth() * $height / $size->getHeight())), $height));
    }

    private function font(?string $path, int $size, string $color): ?\Imagine\Image\FontInterface
    {
        if (null === $path) {
            return null;
        }
        $palette = new RGB();
        $alpha = 100;
        if (9 === \strlen($color)) {
            $alpha = (int) round(hexdec(substr($color, 7, 2)) / 255 * 100);
            $color = substr($color, 0, 7);
        }

        return $this->imagine()->font($path, $size, $palette->color($color, $alpha));
    }

    private function fontPath(): ?string
    {
        foreach (array_filter([$this->font, ...self::FONTS]) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function fontRegularPath(): ?string
    {
        if ($this->fontRegular && is_file($this->fontRegular)) {
            return $this->fontRegular;
        }
        $regular = str_replace('-Bold', '', (string) $this->fontPath());

        return is_file($regular) ? $regular : null;
    }
}
