<?php

require_once 'FileSystem.class.php';
require_once 'PaginationFilter.class.php';


/**
 * The pagination manager for a page split into sub-pages.
 *
 * Pages that display a large number of posts may be split into
 * several sub-pages. The Paginator class handles figuring out what
 * posts to include for the current page number.
 *
 */
class Paginator
{
    protected $pieCrust;
    protected $page;
    
    /**
     * Creates a new Paginator instance.
     */
    public function __construct(PieCrust $pieCrust, Page $page)
    {
        $this->pieCrust = $pieCrust;
        $this->page = $page;
    }
    
    /**
     * Gets the posts for this page.
     *
     * This method is meant to be called from the layouts via the template engine.
     */
    public function posts()
    {
        $pagination = $this->getPaginationData();
        return $pagination['posts'];
    }
    
    /**
     * Gets the previous page's URI.
     *
     * This method is meant to be called from the layouts via the template engine.
     */
    public function prev_page()
    {
        $pagination = $this->getPaginationData();
        return $pagination['prev_page'];
    }
    
    /**
     * Gets this page's URI.
     *
     * This method is meant to be called from the layouts via the template engine.
     */
    public function this_page()
    {
        $pagination = $this->getPaginationData();
        return $pagination['this_page'];
    }
    
    /**
     * Gets thenext page's URI.
     *
     * This method is meant to be called from the layouts via the template engine.
     */
    public function next_page()
    {
        $pagination = $this->getPaginationData();
        return $pagination['next_page'];
    }
    
    /**
     * Gets whether the pagination data was requested by the page.
     */
    public function wasPaginationDataAccessed()
    {
        return ($this->paginationData != null);
    }
    
    /**
     * Gets whether the current page has more pages to show.
     */
    public function hasMorePages()
    {
        return ($this->next_page() != null);
    }
    
    protected $paginationData;
    /**
     * Gets the pagination data for rendering.
     */
    public function getPaginationData()
    {
        if ($this->paginationData === null)
        {
            $this->buildPaginationData();
        }
        return $this->paginationData;
    }
    
    protected $paginationDataSource;
    /**
     * Specifies that the pagination data should be build from the given posts.
     */
    public function setPaginationDataSource(array $postInfos)
    {
        if ($this->paginationData !== null) throw new PieCrustException("The pagination data source can only be set before the pagination data is build.");
        $this->paginationDataSource = $postInfos;
    }
    
    protected function buildPaginationData()
    {
        $filterPostInfos = false;
        $postInfos = $this->paginationDataSource;
        if ($postInfos === null)
        {
            $fs = FileSystem::create($this->pieCrust);
            $postInfos = $fs->getPostFiles();
            $filterPostInfos = true;
        }
    
        $postsData = array();
        $nextPageIndex = null;
        $previousPageIndex = ($this->page->getPageNumber() > 2) ? $this->page->getPageNumber() - 1 : null;
        if (count($postInfos) > 0)
        {
            // Load all the posts for the requested page number (page numbers start at '1').
            $postsPerPage = $this->page->getConfigValue('posts_per_page', 'site');
            $postsDateFormat = $this->page->getConfigValue('date_format', 'site');
            $postsFilter = $this->getPaginationFilter();
            
            $hasMorePages = false;
            $postInfosWithPages = $this->getRelevantPostInfosWithPages($postInfos, $postsFilter, $postsPerPage, $hasMorePages);
            foreach ($postInfosWithPages as $postInfo)
            {
                // Create the post with all the stuff we already know.
                $post = $postInfo['page'];
                $post->setAssetUrlBaseRemap($this->page->getAssetUrlBaseRemap());
                $post->setDate($postInfo);

                // Build the pagination data entry for this post.
                $postData = $post->getConfig();
                $postData['url'] = $this->pieCrust->formatUri($post->getUri());
                $postData['slug'] = $post->getUri();
                
                $timestamp = $post->getDate();
                if ($post->getConfigValue('time')) $timestamp = strtotime($post->getConfigValue('time'), $timestamp);
                $postData['timestamp'] = $timestamp;
                $postData['date'] = date($postsDateFormat, $post->getDate());
                
                $postContents = $post->getContentSegment();
                $postPageBreakOffset = $post->getPageBreakOffset();
                if ($postPageBreakOffset === false) $postData['content'] = $postContents;
                else $postData['content'] = substr($postContents, 0, $postPageBreakOffset);
                $postData['has_more'] = ($postPageBreakOffset !== false);
                
                $postsData[] = $postData;
            }
            
            if ($hasMorePages and !($this->page->getConfigValue('single_page')))
            {
                // There's another page following this one.
                $nextPageIndex = $this->page->getPageNumber() + 1;
            }
        }
        
        $this->paginationData = array(
                                'posts' => $postsData,
                                'prev_page' => ($previousPageIndex == null) ? null : $this->page->getUri() . '/' . $previousPageIndex,
                                'this_page' => $this->page->getUri() . '/' . $this->page->getPageNumber(),
                                'next_page' => ($nextPageIndex == null) ? null : ($this->page->getUri() . '/' . $nextPageIndex)
                                );
    }
    
    protected function getRelevantPostInfosWithPages(array $postInfos, PaginationFilter $postsFilter, $postsPerPage, &$hasMorePages)
    {
        $offset = ($this->page->getPageNumber() - 1) * $postsPerPage;
        $upperLimit = min($offset + $postsPerPage, count($postInfos));
        $postsUrlFormat = $this->pieCrust->getConfigValueUnchecked('site', 'post_url');
        
        if ($postsFilter->hasClauses())
        {
            // We have some filtering clause: that's tricky because we
            // need to filter posts using those clauses from the start to
            // know what offset to start from. This is not very efficient and
            // at this point the user might as well bake his website but hey,
            // this can still be useful for debugging.
            $filteredPostInfos = array();
            foreach ($postInfos as $postInfo)
            {
                $post = PageRepository::getOrCreatePage(
                    $this->pieCrust,
                    Paginator::buildPostUrl($postsUrlFormat, $postInfo), 
                    $postInfo['path'],
                    PIECRUST_PAGE_POST);
                
                if ($postsFilter->postMatches($post))
                {
                    $postInfo['page'] = $post;
                    $filteredPostInfos[] = $postInfo;
                    
                    // Exit if we have enough posts.
                    if (count($filteredPostInfos) >= $upperLimit)
                        break;
                }
            }
            
            // Now get the slice of the filtered post infos that is relevant
            // for the current page number.
            $relevantPostInfos = array_slice($filteredPostInfos, $offset, $upperLimit - $offset);
            $hasMorePages = ($offset + $postsPerPage < count($filteredPostInfos));
            return $relevantPostInfos;
        }
        else
        {
            // This is a normal page, or a situation where we don't do any filtering.
            // That's easy, we just return the portion of the posts-infos array that
            // is relevant to the current page. We just need to add the built page objects.
            $relevantSlice = array_slice($postInfos, $offset, $upperLimit - $offset);
            
            $relevantPostInfos = array();
            foreach ($relevantSlice as $postInfo)
            {
                $postInfo['page'] = PageRepository::getOrCreatePage(
                    $this->pieCrust,
                    Paginator::buildPostUrl($postsUrlFormat, $postInfo), 
                    $postInfo['path'],
                    PIECRUST_PAGE_POST);
                $relevantPostInfos[] = $postInfo;
            }
            $hasMorePages =($offset + $postsPerPage < count($postInfos));
            return $relevantPostInfos;
        }
    }
    
    protected function getPaginationFilter()
    {
        $filter = new PaginationFilter();
        
        // If the current page is a tag/category page, add filtering
        // for that.
        switch ($this->page->getPageType())
        {
        case PIECRUST_PAGE_TAG:
            $filter->addHasClause('tags', $this->page->getPageKey());
            break;
        case PIECRUST_PAGE_CATEGORY:
            $filter->addIsClause('category', $this->page->getPageKey());
            break;
        }
        
        // Add custom filtering clauses specified by the user in the
        // page configuration header.
        $filterInfo = $this->page->getConfigValue('posts_filters');
        if ($filterInfo != null)
            $filter->addClauses($filterInfo);
        
        return $filter;
    }
    
    /**
     * Builds the URL of a post given a URL format.
     */
    public static function buildPostUrl($postUrlFormat, $postInfo)
    {
        $replacements = array(
            '%year%' => $postInfo['year'],
            '%month%' => $postInfo['month'],
            '%day%' => $postInfo['day'],
            '%slug%' => $postInfo['name']
        );
        return str_replace(array_keys($replacements), array_values($replacements), $postUrlFormat);
    }
    
    /**
     * Builds the regex pattern to match the given URL format.
     */
    public static function buildPostUrlPattern($postUrlFormat)
    {
        static $replacements = array(
            '%year%' => '(?P<year>\d{4})',
            '%month%' => '(?P<month>\d{2})',
            '%day%' => '(?P<day>\d{2})',
            '%slug%' => '(?P<slug>.*)'
        );
        return '/^' . str_replace(array_keys($replacements), array_values($replacements), preg_quote($postUrlFormat, '/')) . '\/?$/';
    }
    
    /**
     * Builds the URL of a tag listing.
     */
    public static function buildTagUrl($tagUrlFormat, $tag)
    {
        return str_replace('%tag%', $tag, $tagUrlFormat);
    }
    
    /**
     * Builds the regex pattern to match the given URL format.
     */
    public static function buildTagUrlPattern($tagUrlFormat)
    {
        return '/^' . str_replace('%tag%', '(?P<tag>[\w\-]+)', preg_quote($tagUrlFormat, '/')) . '\/?$/';
    }
    
    /**
     * Builds the URL of a category listing.
     */
    public static function buildCategoryUrl($categoryUrlFormat, $category)
    {
        return str_replace('%category%', $category, $categoryUrlFormat);
    }
    
    /**
     * Builds the regex pattern to match the given URL format.
     */
    public static function buildCategoryUrlPattern($categoryUrlFormat)
    {
        return '/^' . str_replace('%category%', '(?P<cat>[\w\-]+)', preg_quote($categoryUrlFormat, '/')) . '\/?$/';
    }
}
