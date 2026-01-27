import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
};

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface Retailer {
    id: number;
    name: string;
    slug: string;
    base_url: string;
    is_active: boolean;
    product_listings_count?: number;
}

export interface Product {
    id: number;
    name: string;
    slug: string;
    brand: string | null;
    description: string | null;
    category: string | null;
    subcategory: string | null;
    weight_grams: number | null;
    quantity: number | null;
    primary_image: string | null;
    average_price_pence: number | null;
    lowest_price_pence: number | null;
    is_verified: boolean;
    metadata: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
    product_listings?: ProductListing[];
}

export interface ProductListing {
    id: number;
    retailer_id: number;
    external_id: string;
    url: string;
    title: string;
    description: string | null;
    price_pence: number | null;
    original_price_pence: number | null;
    currency: string;
    weight_grams: number | null;
    quantity: number | null;
    brand: string | null;
    category: string | null;
    images: string[] | null;
    ingredients: string | null;
    nutritional_info: Record<string, unknown> | null;
    in_stock: boolean;
    stock_quantity: number | null;
    last_scraped_at: string | null;
    created_at: string;
    updated_at: string;
    retailer?: Retailer;
    prices?: ProductListingPrice[];
    reviews?: ProductListingReview[];
}

export interface ProductListingPrice {
    id: number;
    product_listing_id: number;
    price_pence: number;
    original_price_pence: number | null;
    currency: string;
    recorded_at: string;
}

export interface ProductListingReview {
    id: number;
    product_listing_id: number;
    external_id: string;
    author: string | null;
    rating: number;
    title: string | null;
    body: string | null;
    verified_purchase: boolean;
    review_date: string | null;
    helpful_count: number;
    metadata: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
}

export interface PriceDrop {
    listing: ProductListing;
    product: Product | null;
    previous_price_pence: number;
    current_price_pence: number;
    drop_percentage: number;
}

export interface PriceHistoryEntry {
    date: string;
    prices: Record<string, number>;
}

export interface ProductFilters {
    search: string;
    brand: string;
    category: string;
    retailer: string;
    min_price: string;
    max_price: string;
    sort: string;
    dir: string;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    first_page_url: string;
    from: number | null;
    last_page: number;
    last_page_url: string;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
    next_page_url: string | null;
    path: string;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
    total: number;
}
