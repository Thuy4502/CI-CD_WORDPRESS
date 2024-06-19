<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'myshop' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'UDGhv`t*) jLu!kpaNiv(/c1ch[9zU_Ux)C@sTgKv_ &2TH=W>,|W+}-;zs{X!f$' );
define( 'SECURE_AUTH_KEY',  ')/8l*K)aMe/]cH[P~Vyt`_TA,_Zxp^0SAZxo~JUH^ZF ^0;z27lqs_o0-}_vHN5t' );
define( 'LOGGED_IN_KEY',    'EG?s*p]Kp.a%Ko(mUIWR@H=|lw4KXX>9norc8[{f.P+6n;2Y/te,VkWgP18@M1* ' );
define( 'NONCE_KEY',        'x>3#%eM)WQw@j[v*;l3e}EFc1*rX>*Xlr`tpQZPs^Pv]W/|A,M3e]{cDeXQB0.?g' );
define( 'AUTH_SALT',        'B|rF{BQpvNLkPIeK}r+~)G-=h349gr(}VeS%JHw:7|hA3W=G-x-L^_xVu(XTpR6h' );
define( 'SECURE_AUTH_SALT', 'FYL|Vn dn9q{uki*Opuho@CaTj(3Q=TH(8s[BS0D+/riUvm_+4W?8n4#wzrX6SRM' );
define( 'LOGGED_IN_SALT',   'cGC{d~V5Jy#Jp|&y@87!l^Gw0nc@H4<-pw492;9}djkHv3ZxH+Uy{]q4Gy;)4#p ' );
define( 'NONCE_SALT',       '1|R!vN 65.i:g:i#]@6KtHP,2Yo{+,n7U,cWAu~7OVF`Jr-pO6OW?.Axn`iwwAl_' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
