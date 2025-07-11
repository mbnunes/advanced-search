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

        <div id="app-content-wrapper">
    <!-- Empty content e loading... -->
    
    <table id="filestable" class="list-container">
        <!-- Tabela... -->
    </table>
    
    <!-- Paginação FORA da tabela -->
    <div class="pagination-wrapper hidden" id="pagination">
        <div class="pagination-info">
            <span id="pagination-info">Mostrando 0-0 de 0 resultados</span>
        </div>
        
        <div class="pagination-controls">
            <div class="pagination-buttons">
                <button class="pagination-button" id="first-page">«</button>
                <button class="pagination-button" id="prev-page">‹</button>
                
                <div id="page-numbers" class="pagination-buttons">
                    <!-- Números das páginas -->
                </div>
                
                <button class="pagination-button" id="next-page">›</button>
                <button class="pagination-button" id="last-page">»</button>
            </div>
            
            <div class="page-size-selector">
                <label for="page-size"><?php p($l->t('Items per page')); ?>:</label>
                <select id="page-size">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</div>
    </div>
</div>