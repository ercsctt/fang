/**
 * TypeScript declarations for Wayfinder generated routes
 *
 * This file augments the auto-generated Wayfinder types to include
 * the .form() method and other runtime-added properties that TypeScript
 * doesn't know about from the generated code.
 */

import type {
    RouteDefinition,
    RouteFormDefinition,
    RouteQueryOptions,
} from '@/wayfinder';

/**
 * Wayfinder route function type with all runtime-added properties
 *
 * The Wayfinder plugin generates route functions that have additional
 * properties added at runtime (like .form, .url, and method-specific variants).
 * This type declaration makes TypeScript aware of these properties.
 */
export type WayfinderRoute = ((
    options?: RouteQueryOptions,
) => RouteDefinition<any>) & {
    /** Get the URL string for this route */
    url: (options?: RouteQueryOptions) => string;

    /** Get form attributes (action and method) for HTML forms */
    form: ((options?: RouteQueryOptions) => RouteFormDefinition<any>) & {
        get?: (options?: RouteQueryOptions) => RouteFormDefinition<any>;
        post?: (options?: RouteQueryOptions) => RouteFormDefinition<any>;
        put?: (options?: RouteQueryOptions) => RouteFormDefinition<any>;
        patch?: (options?: RouteQueryOptions) => RouteFormDefinition<any>;
        delete?: (options?: RouteQueryOptions) => RouteFormDefinition<any>;
        head?: (options?: RouteQueryOptions) => RouteFormDefinition<any>;
        options?: (options?: RouteQueryOptions) => RouteFormDefinition<any>;
    };

    /** Route definition metadata */
    definition: RouteDefinition<any>;

    /** HTTP method-specific route functions */
    get?: (options?: RouteQueryOptions) => RouteDefinition<any>;
    post?: (options?: RouteQueryOptions) => RouteDefinition<any>;
    put?: (options?: RouteQueryOptions) => RouteDefinition<any>;
    patch?: (options?: RouteQueryOptions) => RouteDefinition<any>;
    delete?: (options?: RouteQueryOptions) => RouteDefinition<any>;
    head?: (options?: RouteQueryOptions) => RouteDefinition<any>;
    options?: (options?: RouteQueryOptions) => RouteDefinition<any>;
};
