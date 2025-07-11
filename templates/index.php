<?php
script('advancedsearch', 'search');
style('advancedsearch', 'style');
?>

<div id="app">
    <div id="app-navigation">
        <div class="app-navigation-new">
            <h2><?php p($l->t('Advanced Search')); ?></h2>
        </div>
        
        <div class="search-sidebar">
            <div class="search-section">
                <h3><?php p($l->t('Search Criteria')); ?></h3>
                
                <div class="search-form">
                    <div class="form-group">
                        <label for="filename"><?php p($l->t('File Name')); ?>:</label>
                        <input type="text" id="filename" class="input-field" placeholder="<?php p($l->t('Enter file name...')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="tags"><?php p($l->t('Tags')); ?>:</label>
                        <input type="text" id="tags" class="input-field" placeholder="<?php p($l->t('Enter tags separated by comma...')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><?php p($l->t('Tag Operator')); ?>:</label>
                        <div class="radio-group">
                            <input type="radio" id="tag-and" name="tagOperator" value="AND" checked>
                            <label for="tag-and"><?php p($l->t('AND (all tags)')); ?></label>
                            
                            <input type="radio" id="tag-or" name="tagOperator" value="OR">
                            <label for="tag-or"><?php p($l->t('OR (any tag)')); ?></label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="file-type"><?php p($l->t('File Type')); ?>:</label>
                        <select id="file-type" class="input-field">
                            <option value=""><?php p($l->t('All types')); ?></option>
                            <option value="image"><?php p($l->t('Images')); ?></option>
                            <option value="document"><?php p($l->t('Documents')); ?></option>
                            <option value="video"><?php p($l->t('Videos')); ?></option>
                            <option value="audio"><?php p($l->t('Audio')); ?></option>
                            <option value="pdf"><?php p($l->t('PDF')); ?></option>
                        </select>
                    </div>
                    
                    <button id="search-btn" class="primary button"><?php p($l->t('Search')); ?></button>
                    <button id="clear-btn" class="button"><?php p($l->t('Clear')); ?></button>
                </div>
            </div>
            
            <div class="search-stats">
                <div id="search-info">
                    <span id="result-count"></span>
                </div>
            </div>
        </div>
    </div>

    <div id="app-content">
        <div id="app-content-wrapper">
            <div class="files-controls">
                <div class="breadcrumb">
                    <div class="crumb svg canDrop">
                        <a href="#" class="icon-home"></a>
                        <span><?php p($l->t('Search Results')); ?></span>
                    </div>
                </div>
                
                <div class="view-controls">
                    <div class="view-switch">
                        <button id="view-list" class="icon-view-list active" title="<?php p($l->t('List view')); ?>"></button>
                        <button id="view-grid" class="icon-view-grid" title="<?php p($l->t('Grid view')); ?>"></button>
                    </div>
                </div>
            </div>
            
            <div id="emptycontent" class="hidden">
                <div class="icon-search"></div>
                <h2><?php p($l->t('No results found')); ?></h2>
                <p><?php p($l->t('Try adjusting your search criteria')); ?></p>
            </div>
            
            <div id="loading" class="hidden">
                <div class="icon-loading"></div>
                <h2><?php p($l->t('Searching...')); ?></h2>
            </div>
            
            <table id="filestable" class="list-container">
                <thead>
                    <tr>
                        <th id="headerName" class="column-name">
                            <div id="headerName-content">
                                <span class="sort-indicator icon-triangle-s"></span>
                                <span><?php p($l->t('Name')); ?></span>
                            </div>
                        </th>
                        <th id="headerSize" class="column-size">
                            <span><?php p($l->t('Size')); ?></span>
                        </th>
                        <th id="headerDate" class="column-mtime">
                            <span><?php p($l->t('Modified')); ?></span>
                        </th>
                        <th id="headerTags" class="column-tags">
                            <span><?php p($l->t('Tags')); ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody id="fileList">
                    <!-- Resultados serão inseridos aqui -->
                </tbody>
            </table>
        </div>
    </div>
</div>