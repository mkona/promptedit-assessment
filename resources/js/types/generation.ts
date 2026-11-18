export type GenerationStatus =
    | 'submitting'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_price: string;
    max_price: string;
    price: string | null;
    image_url: string | null;
    image_expired: boolean;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

export type Estimate = {
    expected: string;
    minimum: string;
    maximum: string;
};
