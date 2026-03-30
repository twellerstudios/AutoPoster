<?php
/**
 * Custom Search Form
 *
 * @package TwellerStudios
 */
?>
<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" style="max-width: 500px; margin: 0 auto; display: flex; gap: 1rem;">
    <input type="search" class="tw-form__input" placeholder="Search..." value="<?php echo get_search_query(); ?>" name="s" aria-label="Search">
    <button type="submit" class="tw-btn tw-btn--primary">Search</button>
</form>
