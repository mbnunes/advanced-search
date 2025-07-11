<div class="advanced-search-app">
    <div class="search-container">
        <h2><?php p($l->t('Advanced File Search')); ?></h2>
        
        <div class="search-form">
            <div class="form-group">
                <label for="filename"><?php p($l->t('File Name')); ?>:</label>
                <input type="text" id="filename" placeholder="<?php p($l->t('Enter file name...')); ?>">
            </div>
            
            <div class="form-group">
                <label for="tags"><?php p($l->t('Tags')); ?>:</label>
                <input type="text" id="tags" placeholder="<?php p($l->t('Enter tags separated by comma...')); ?>">
            </div>
            
            <div class="form-group">
                <label><?php p($l->t('Tag Operator')); ?>:</label>
                <input type="radio" id="tag-and" name="tagOperator" value="AND" checked>
                <label for="tag-and"><?php p($l->t('AND (all tags)')); ?></label>
                
                <input type="radio" id="tag-or" name="tagOperator" value="OR">
                <label for="tag-or"><?php p($l->t('OR (any tag)')); ?></label>
            </div>
            
            <button id="search-btn"><?php p($l->t('Search')); ?></button>
        </div>
        
        <div id="search-results"></div>
    </div>
</div>