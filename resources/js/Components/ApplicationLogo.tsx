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
                d="M14 26.5 42 17v30L14 37.5v-11Z"
                fill="white"
                stroke="white"
                strokeWidth="3"
                strokeLinejoin="round"
            />
            <path
                d="M42 22h4.5a4.5 4.5 0 0 1 4.5 4.5v11a4.5 4.5 0 0 1-4.5 4.5H42V22Z"
                stroke="white"
                strokeWidth="3.5"
                strokeLinejoin="round"
            />
            <path
                d="m23 40 3 10h10l-4-7"
                stroke="#FDE68A"
                strokeWidth="4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="m10 23-3-3m3 21-3 3m2-12H5"
                stroke="#FDE68A"
                strokeWidth="3"
                strokeLinecap="round"
            />
        </svg>
    );
}
