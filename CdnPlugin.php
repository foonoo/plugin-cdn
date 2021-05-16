<?php

namespace foonoo\plugins\contrib\cdn;

use clearice\io\Io;
use foonoo\content\Content;
use foonoo\events\ContentGenerationStarted;
use foonoo\events\ContentLayoutApplied;
use foonoo\events\ContentOutputGenerated;
use foonoo\events\SiteWriteStarted;
use foonoo\Plugin;
use foonoo\sites\AbstractSite;

/**
 * Class CdnPlugin
 */
class CdnPlugin extends Plugin
{
    private $imageDirectory;
    private $imageDirectoryLen;
    private $assetsDirectory;
    private $assetsDirectoryLen;
    private $supportedTags;

    /**
     * @return \Closure[]
     */
    public function getEvents()
    {
        $this->supportedTags = ['//img' => [$this, 'processImg'], '//picture//source' => [$this, 'processPicture']];

        return [
            ContentLayoutApplied::class => function (ContentLayoutApplied $event) { $this->processMarkup($event); },
            SiteWriteStarted::class => function (SiteWriteStarted  $event) {
                $this->imageDirectory = $event->getSite()->getDestinationPath("np_images");
                $this->imageDirectoryLen = strlen($this->imageDirectory);
                $this->assetsDirectory = $event->getSite()->getDestinationPath("assets");
                $this->assetsDirectoryLen = strlen($this->assetsDirectory);
            }
        ];
    }

    private function getDestinationType($path): array
    {
        if(substr($path, 0, $this->imageDirectoryLen) == $this->imageDirectory) return [$this->imageDirectoryLen, "/images"];
        if(substr($path, 0, $this->assetsDirectoryLen) == $this->assetsDirectory) return [$this->assetsDirectoryLen, "/assets"];
        return [0, ""];
    }

    private function getUrl(string $src, AbstractSite $site, Content $content)
    {
        $destination = realpath(dirname($site->getDestinationPath($content->getDestination())) . "/$src");
        if($destination !== false) {
            list($len, $type) = $this->getDestinationType($destination);
            return $this->getOption("base_url", "https://cdn.hotocameras.com") . $type . substr($destination, $len);
        }
        return false;
    }

    private function processImg(\DOMElement $tag, Content $content, AbstractSite $site)
    {
        $imageDestination = $this->getUrl($tag->getAttribute('src'), $site, $content);
        if($imageDestination) {
            $tag->setAttribute("src", $imageDestination);
        }
    }

    private function processSrcSet(string $srcset, Content $content, AbstractSite $site): string
    {
        $splitSrc = explode(",", $srcset);
        foreach($splitSrc as $i => $src) {
            $parts = explode(" ", trim($src));
            $translated = $this->getUrl($parts[0], $site, $content);
            if($translated) {
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

    private function processMarkup(ContentLayoutApplied $event)
    {
        if(!$event->hasDOM()) {
            return;
        }
        $xpath = new \DOMXPath($event->getDOM());
        $content = $event->getContent();
        $site = $event->getSite();
        foreach($this->supportedTags as $query => $processor) {
            $tags = $xpath->query("$query");
            foreach($tags as $tag) {
                $processor($tag, $content, $site);
            }
        }
    }
}