import type { ImgHTMLAttributes } from 'react';

/**
 * The Zomboid Manager brand mark: zombie skull fused with a server-rack gear.
 *
 * Raster rather than inline SVG, so `fill-current` has no effect here - the
 * mark is multi-colour by design and must not be recoloured by the caller.
 */
export default function AppLogoIcon(props: ImgHTMLAttributes<HTMLImageElement>) {
    return (
        <img
            src="/images/brand/logo-mark.png"
            alt="Zomboid Manager"
            {...props}
        />
    );
}
