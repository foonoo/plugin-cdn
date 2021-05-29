<?php

namespace foonoo\plugins\contrib\cdn;

use clearice\io\Io;
use foonoo\content\Content;
use foonoo\events\ContentLayoutApplied;
use foonoo\events\PluginsInitialized;
use foonoo\events\SiteWriteStarted;
use foonoo\Plugin;
use foonoo\sites\AbstractSite;

/**
 * A plugin that replaces links in selected tags with corresponding CDN links.
 * Note that this plugin requires you to find other ways to transfer your content to the CDN.
 */
class CdnPlugin extends Plugin
{
    /**
     * Path to local image directory.
     * @var string
     */
    private $imageDirectory;

    /**
     * Cached length of the image directory path.
     * @var string
     */
    private $imageDirectoryLen;

    /**
     * Path to local assets directory.
     * @var string
     */
    private $assetsDirectory;
    private $assetsDirectoryLen;
    private $supportedTags;
    private $extensions = [];


    /**
     * @return \Closure[]
     */
    public function getEvents()
    {
        $this->supportedTags = [
            '//img' => [$this, 'processImg'], '//picture//source' => [$this, 'processPicture'],
            "//script" => [$this, 'processScript'], "//link" => [$this, 'processHreffed'],
            "//a" => [$this, 'processHreffed']
        ];

        return [
            ContentLayoutApplied::class => function (ContentLayoutApplied $event) {
                $this->processMarkup($event);
            },
            SiteWriteStarted::class => function (SiteWriteStarted $event) {
                $this->imageDirectory = $event->getSite()->getDestinationPath("np_images");
                $this->imageDirectoryLen = strlen($this->imageDirectory);
                $this->assetsDirectory = $event->getSite()->getDestinationPath("assets");
                $this->assetsDirectoryLen = strlen($this->assetsDirectory);
            },
            PluginsInitialized::class => function () {
                $this->extensions = $this->getOption('extensions',
                    ['jpg', 'jpeg', 'png', 'webp', 'gif', 'js', 'css', 'woff', 'ttf', 'otf', 'svg']
                );
            }
        ];
    }

    /**
     * Determines whether a path points to images or assets.
     *
     * @param $path
     * @return array
     */
    private function getDestinationType($path): array
    {
        if (substr($path, 0, $this->imageDirectoryLen) == $this->imageDirectory) return [$this->imageDirectoryLen, "/images"];
        if (substr($path, 0, $this->assetsDirectoryLen) == $this->assetsDirectory) return [$this->assetsDirectoryLen, "/assets"];
        return [0, ""];
    }

    /**
     * Get the corresponding CDN URL for a given local path.
     *
     * @param string $src
     * @param AbstractSite $site
     * @param Content $content
     * @return false|string
     */
    private function getUrl(string $src, AbstractSite $site, Content $content)
    {
        if($src == "") {
            return false;
        }
        if(!in_array(strtolower(pathinfo($src, PATHINFO_EXTENSION)), $this->extensions)) {
            return false;
        }
        $destination = realpath(dirname($site->getDestinationPath($content->getDestination())) . "/$src");
        if ($destination !== false) {
            list($len, $type) = $this->getDestinationType($destination);
            return $this->getOption("base_url", "https://cdn.hotocameras.com") . $type . substr($destination, $len);
        }
        return false;
    }

    private function setUrlAttribute(\DOMElement $tag, string $attribute, Content $content, AbstractSite $site)
    {
        $value = $tag->getAttribute($attribute);
        if($value == "") {
            return;
        }
        $destination = $this->getUrl($value, $site, $content);
        if ($destination) {
            $tag->setAttribute($attribute, $destination);
        }
    }

    /**
     * Process an img tag
     *
     * @param \DOMElement $tag
     * @param Content $content
     * @param AbstractSite $site
     */
    private function processImg(\DOMElement $tag, Content $content, AbstractSite $site)
    {
        $src = $tag->getAttribute('src');
        if ($src) {
            $this->setUrlAttribute($tag, 'src', $content, $site);
            return;
        }
        $srcset = $tag->getAttribute('srcset');
        if ($srcset) {
            $srcset = $this->processSrcSet($srcset, $content, $site);
            $tag->setAttribute('srcset', $srcset);
        }
    }

    /**
     * Process an srcset attribute
     *
     * @param string $srcset
     * @param Content $content
     * @param AbstractSite $site
     * @return string
     */
    private function processSrcSet(string $srcset, Content $content, AbstractSite $site): string
    {
        $splitSrc = explode(",", $srcset);
        foreach ($splitSrc as $i => $src) {
            $parts = explode(" ", trim($src));
            $translated = $this->getUrl($parts[0], $site, $content);
            if ($translated) {
                $parts[0] = $translated;
            }
            $splitSrc[$i] = implode(" ", $parts);
        }
        return implode(", ", $splitSrc);
    }

    private function processPicture(\DOMElement $tag, Content $content, AbstractSite $site)
    {
        $tag->setAttribute('srcset', $this->processSrcSet($tag->getAttribute('srcset'), $content, $site));
    }

    private function processHreffed(\DOMElement $tag, Content $content, AbstractSite $site)
    {
        $this->setUrlAttribute($tag, 'href', $content, $site);
    }

    private function processScript(\DOMElement $tag, Content $content, AbstractSite $site)
    {
        $this->setUrlAttribute($tag, 'src', $content, $site);
    }

    private function processMarkup(ContentLayoutApplied $event)
    {
        if (!$event->hasDOM()) {
            return;
        }
        $this->stdOut("Replacing URLs in {$event->getContent()->getDestination()}\n");
        $xpath = new \DOMXPath($event->getDOM());
        $content = $event->getContent();
        $site = $event->getSite();
        foreach ($this->supportedTags as $query => $processor) {
            $tags = $xpath->query("$query");
            foreach ($tags as $tag) {
                $processor($tag, $content, $site);
            }
        }
    }
}