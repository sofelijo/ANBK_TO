import { SVGAttributes } from 'react';

export default function ApplicationLogo(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 64 64"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <rect width="64" height="64" rx="18" fill="#059669" />
            <path
                d="M14 18.5c7.47 0 11.73 1.73 18 6.5v25c-6.27-4.77-10.53-6.5-18-6.5v-25Z"
                stroke="white"
                strokeWidth="3.5"
                strokeLinejoin="round"
            />
            <path
                d="M50 18.5c-7.47 0-11.73 1.73-18 6.5v25c6.27-4.77 10.53-6.5 18-6.5v-25Z"
                stroke="white"
                strokeWidth="3.5"
                strokeLinejoin="round"
            />
            <path
                d="m44 9 1.4 3.1L48.5 13.5l-3.1 1.4L44 18l-1.4-3.1-3.1-1.4 3.1-1.4L44 9Z"
                fill="#FDE68A"
            />
        </svg>
    );
}
